<?php

namespace Tests\Feature;

use App\Actions\Branches\ReplaceBusinessHoursAction;
use App\Actions\Settings\UpdateRestaurantSettingsAction;
use App\Models\Branch;
use App\Models\BranchClosure;
use App\Models\User;
use App\Services\BranchScheduleService;
use App\Services\BusinessHoursOverlapValidator;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BranchScheduleTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[DataProvider('scheduleTimes')]
    public function test_shift_boundaries_multiple_periods_and_friday_overnight(string $time, bool $open): void
    {
        $branch = $this->schedule();
        $at = CarbonImmutable::parse($time, 'Asia/Riyadh');
        $this->travelTo($at);
        $service = app(BranchScheduleService::class);
        $this->assertSame($open, $service->isOpenNow($branch));
        $this->assertSame($open, $service->isOpenAt($branch, $at->utc()));
        $this->travelBack();
    }

    public static function scheduleTimes(): array
    {
        return [
            ['2026-10-02 10:59:59', false], ['2026-10-02 11:00:00', true], ['2026-10-02 14:59:59', true],
            ['2026-10-02 15:00:00', false], ['2026-10-02 17:59:59', false], ['2026-10-02 18:00:00', true],
            ['2026-10-02 23:59:59', true], ['2026-10-03 00:00:00', true], ['2026-10-03 01:00:00', true],
            ['2026-10-03 02:59:59', true], ['2026-10-03 03:00:00', false], ['2026-10-03 12:00:00', false],
        ];
    }

    public function test_no_schedule_is_closed_and_has_no_next_opening(): void
    {
        $branch = Branch::factory()->withoutBusinessHours()->create();
        $this->assertFalse(app(BranchScheduleService::class)->isOpenNow($branch));
        $this->assertNull(app(BranchScheduleService::class)->nextOpeningTime($branch));
    }

    public function test_next_opening_and_current_period_are_utc(): void
    {
        $branch = $this->schedule();
        $service = app(BranchScheduleService::class);
        $this->assertSame('2026-10-02T15:00:00.000000Z', $service->nextOpeningTime($branch, CarbonImmutable::parse('2026-10-02 16:00', 'Asia/Riyadh'))->toISOString());
        $period = $service->currentPeriod($branch, CarbonImmutable::parse('2026-10-03 01:00', 'Asia/Riyadh'));
        $this->assertSame('2026-10-02T15:00:00.000000Z', $period['opens_at']->toISOString());
        $this->assertSame('2026-10-03T00:00:00.000000Z', $period['closes_at']->toISOString());
    }

    public function test_active_closure_overrides_hours_and_next_opening_returns_actual_resume_time(): void
    {
        $branch = $this->schedule();
        BranchClosure::factory()->for($branch)->create(['starts_at' => '2026-10-02 08:00:00', 'ends_at' => '2026-10-02 10:00:00']);
        $service = app(BranchScheduleService::class);
        $at = CarbonImmutable::parse('2026-10-02T09:00:00Z');
        $this->assertFalse($service->isOpenAt($branch, $at));
        $this->assertSame('2026-10-02T10:00:00.000000Z', $service->nextOpeningTime($branch, $at)->toISOString());
        $this->assertTrue($service->isOpenAt($branch, CarbonImmutable::parse('2026-10-02T10:00:00Z')));
    }

    public function test_next_opening_skips_multiple_overlapping_closures_and_whole_periods(): void
    {
        $branch = $this->schedule();
        foreach ([['08:00', '16:00'], ['15:00', '18:00']] as [$start,$end]) {
            BranchClosure::factory()->for($branch)->create(['starts_at' => '2026-10-02 '.$start, 'ends_at' => '2026-10-02 '.$end]);
        }
        $next = app(BranchScheduleService::class)->nextOpeningTime($branch, CarbonImmutable::parse('2026-10-02T07:00:00Z'));
        $this->assertSame('2026-10-02T18:00:00.000000Z', $next->toISOString());
    }

    public function test_next_opening_is_bounded_to_fourteen_days(): void
    {
        $branch = $this->schedule();
        BranchClosure::factory()->for($branch)->create(['starts_at' => '2026-10-01', 'ends_at' => '2026-11-01']);
        $this->assertNull(app(BranchScheduleService::class)->nextOpeningTime($branch, CarbonImmutable::parse('2026-10-02T07:00:00Z')));
    }

    #[DataProvider('ignoredClosures')]
    public function test_inactive_future_and_expired_closures_do_not_close_current_shift(array $data): void
    {
        $branch = $this->schedule();
        BranchClosure::factory()->for($branch)->create($data);
        $this->assertTrue(app(BranchScheduleService::class)->isOpenAt($branch, CarbonImmutable::parse('2026-10-02T09:00:00Z')));
    }

    public static function ignoredClosures(): array
    {
        return [[['starts_at' => '2026-10-02 08:00', 'ends_at' => '2026-10-02 12:00', 'is_active' => false]],
            [['starts_at' => '2026-10-03', 'ends_at' => '2026-10-04']],
            [['starts_at' => '2026-10-01', 'ends_at' => '2026-10-02 08:00']]];
    }

    #[DataProvider('overlapCases')]
    public function test_circular_week_overlap_validation(array $hours, bool $valid): void
    {
        if (! $valid) {
            $this->expectException(ValidationException::class);
        }
        app(BusinessHoursOverlapValidator::class)->validate($hours);
        if ($valid) {
            $this->assertTrue(true);
        }
    }

    public static function overlapCases(): array
    {
        $h = fn ($day, $open, $close) => ['day_of_week' => $day, 'opens_at' => $open, 'closes_at' => $close];

        return [
            [[$h(1, '11:00', '18:00'), $h(1, '17:00', '22:00')], false],
            [[$h(1, '11:00', '15:00'), $h(1, '17:00', '22:00')], true],
            [[$h(1, '11:00', '15:00'), $h(1, '15:00', '22:00')], true],
            [[$h(5, '18:00', '03:00'), $h(6, '02:00', '12:00')], false],
            [[$h(7, '18:00', '03:00'), $h(1, '02:00', '12:00')], false],
            [[$h(7, '18:00', '03:00'), $h(1, '03:00', '12:00')], true],
            [[$h(1, '18:00', '03:00'), $h(1, '20:00', '02:00')], false],
            [[$h(1, '11:00', '11:00')], false],
        ];
    }

    public function test_hours_api_replaces_full_week_and_exposes_ordered_days(): void
    {
        $branch = $this->schedule();
        $this->admin();
        $url = '/api/v1/admin/branches/'.$branch->uuid.'/business-hours';
        $this->putJson($url, ['hours' => [['day_of_week' => 1, 'opens_at' => '18:00', 'closes_at' => '03:00']]])
            ->assertOk()->assertJsonPath('data.days.0.day_name', 'monday')->assertJsonPath('data.days.0.periods.0.closes_at', '03:00');
        $this->assertSame(1, $branch->businessHours()->count());
        $this->getJson('/api/v1/branches/'.$branch->uuid.'/business-hours')->assertOk()->assertJsonCount(7, 'data.days')->assertJsonCount(0, 'data.days.4.periods');
        $this->putJson($url, ['hours' => []])->assertOk();
        $this->assertSame(0, $branch->businessHours()->count());
    }

    public function test_invalid_overlapping_hours_do_not_replace_previous_schedule(): void
    {
        $branch = $this->schedule();
        $this->admin();
        $this->putJson('/api/v1/admin/branches/'.$branch->uuid.'/business-hours', ['hours' => [
            ['day_of_week' => 7, 'opens_at' => '18:00', 'closes_at' => '03:00'],
            ['day_of_week' => 1, 'opens_at' => '02:00', 'closes_at' => '12:00'],
        ]])->assertUnprocessable();
        $this->assertSame(2, $branch->businessHours()->count());
    }

    #[DataProvider('invalidHours')]
    public function test_hours_api_rejects_invalid_fields(array $row): void
    {
        $branch = $this->schedule();
        $this->admin();
        $this->putJson('/api/v1/admin/branches/'.$branch->uuid.'/business-hours', ['hours' => [$row]])->assertUnprocessable();
        $this->assertSame(2, $branch->businessHours()->count());
    }

    public static function invalidHours(): array
    {
        return [[['day_of_week' => 0, 'opens_at' => '11:00', 'closes_at' => '12:00']],
            [['day_of_week' => 8, 'opens_at' => '11:00', 'closes_at' => '12:00']],
            [['day_of_week' => 1, 'opens_at' => '25:00', 'closes_at' => '12:00']],
            [['day_of_week' => 1, 'closes_at' => '12:00']],
            [['day_of_week' => 1, 'opens_at' => '11:00', 'closes_at' => '12:00', 'is_closed' => true]]];
    }

    public function test_closure_crud_uses_utc_and_checks_merged_partial_range_and_branch_ownership(): void
    {
        $branch = $this->schedule();
        $other = Branch::factory()->create();
        $this->admin();
        $url = '/api/v1/admin/branches/'.$branch->uuid.'/closures';
        $response = $this->postJson($url, ['starts_at' => '2026-10-02T11:00:00+03:00', 'ends_at' => '2026-10-02T13:00:00+03:00', 'reason' => 'صيانة'])
            ->assertCreated()->assertJsonPath('data.starts_at', '2026-10-02T08:00:00.000000Z');
        $uuid = $response->json('data.uuid');
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data');
        $this->patchJson($url.'/'.$uuid, ['ends_at' => '2026-10-02T07:00:00Z'])->assertUnprocessable();
        $this->patchJson($url.'/'.$uuid, ['starts_at' => '2026-10-02T12:00:00Z'])->assertUnprocessable();
        $this->patchJson('/api/v1/admin/branches/'.$other->uuid.'/closures/'.$uuid, ['is_active' => false])->assertNotFound();
        $this->deleteJson('/api/v1/admin/branches/'.$other->uuid.'/closures/'.$uuid)->assertNotFound();
        $this->patchJson($url.'/'.$uuid, ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->deleteJson($url.'/'.$uuid)->assertOk();
        $this->assertDatabaseCount('branch_closures', 0);
    }

    #[DataProvider('invalidClosure')]
    public function test_invalid_closure_range_or_ambiguous_timezone_rejected(array $data): void
    {
        $branch = $this->schedule();
        $this->admin();
        $this->postJson('/api/v1/admin/branches/'.$branch->uuid.'/closures', $data)->assertUnprocessable();
        $this->assertDatabaseCount('branch_closures', 0);
    }

    public static function invalidClosure(): array
    {
        return [[['starts_at' => '2026-10-02T11:00:00Z', 'ends_at' => '2026-10-02T11:00:00Z']],
            [['starts_at' => '2026-10-02T12:00:00Z', 'ends_at' => '2026-10-02T11:00:00Z']],
            [['starts_at' => '2026-10-02 11:00', 'ends_at' => '2026-10-02 12:00']],
            [['starts_at' => '2026-02-30T11:00:00Z', 'ends_at' => '2026-03-03T12:00:00Z']]];
    }

    #[DataProvider('operationalEndpoints')]
    public function test_branch_administration_requires_each_permission(string $method, string $suffix, array $body, string $permission): void
    {
        $branch = $this->schedule();
        $closure = BranchClosure::factory()->for($branch)->create();
        $path = '/api/v1/admin/branches/'.$branch->uuid.str_replace('{closure}', $closure->uuid, $suffix);
        $this->seed(RolePermissionSeeder::class);
        $this->json($method, $path, $body)->assertUnauthorized();
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')->json($method, $path, $body)->assertForbidden();
        $user->givePermissionTo($permission);
        $this->actingAs($user->fresh(), 'sanctum')->json($method, $path, $body)->assertSuccessful();
    }

    public static function operationalEndpoints(): array
    {
        return [['GET', '/business-hours', [], 'branches.hours.view'], ['PUT', '/business-hours', ['hours' => []], 'branches.hours.update'],
            ['GET', '/closures', [], 'branches.closures.view'],
            ['POST', '/closures', ['starts_at' => '2026-10-02T10:00:00Z', 'ends_at' => '2026-10-02T12:00:00Z'], 'branches.closures.create'],
            ['PATCH', '/closures/{closure}', ['is_active' => false], 'branches.closures.update'],
            ['DELETE', '/closures/{closure}', [], 'branches.closures.delete']];
    }

    public function test_settings_timezone_is_used_instead_of_server_timezone(): void
    {
        $branch = $this->schedule();
        app(UpdateRestaurantSettingsAction::class)->handle(['restaurant.timezone' => 'UTC'], User::factory()->create());
        $this->assertFalse(app(BranchScheduleService::class)->isOpenAt($branch, CarbonImmutable::parse('2026-10-02T09:00:00Z')));
        $this->assertTrue(app(BranchScheduleService::class)->isOpenAt($branch, CarbonImmutable::parse('2026-10-02T12:00:00Z')));
    }

    public function test_overnight_closing_uses_next_calendar_day_across_dst(): void
    {
        $branch = Branch::factory()->withoutBusinessHours()->create();
        app(ReplaceBusinessHoursAction::class)->handle($branch, [['day_of_week' => 6, 'opens_at' => '18:00', 'closes_at' => '04:00']]);
        app(UpdateRestaurantSettingsAction::class)->handle(['restaurant.timezone' => 'America/New_York'], User::factory()->create());
        $service = app(BranchScheduleService::class);
        $this->assertTrue($service->isOpenAt($branch, CarbonImmutable::parse('2026-11-01T08:59:59Z')));
        $this->assertFalse($service->isOpenAt($branch, CarbonImmutable::parse('2026-11-01T09:00:00Z')));
    }

    private function schedule(): Branch
    {
        $branch = Branch::factory()->withoutBusinessHours()->create();

        return app(ReplaceBusinessHoursAction::class)->handle($branch, [
            ['day_of_week' => 5, 'opens_at' => '11:00', 'closes_at' => '15:00'],
            ['day_of_week' => 5, 'opens_at' => '18:00', 'closes_at' => '03:00']]);
    }

    private function admin(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->actingAs(User::factory()->create()->assignRole('super_admin'), 'sanctum');
    }
}
