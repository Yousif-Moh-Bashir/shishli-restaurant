<?php

namespace Tests\Feature\Api\V1;

use App\Models\CustomerAddress;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerAddressTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('endpoints')]
    public function test_guests_cannot_access_addresses(string $method, string $suffix): void
    {
        $address = CustomerAddress::factory()->create();
        $this->json($method, '/api/v1/addresses'.str_replace('{uuid}', $address->uuid, $suffix), $this->payload())->assertUnauthorized();
    }

    public static function endpoints(): array
    {
        return [['GET', ''], ['POST', ''], ['GET', '/{uuid}'], ['PATCH', '/{uuid}'], ['DELETE', '/{uuid}'], ['PATCH', '/{uuid}/default']];
    }

    public function test_creation_listing_default_switching_and_delete_preserve_ownership(): void
    {
        $user = User::factory()->create();
        $other = CustomerAddress::factory()->create();
        $this->actingAs($user, 'sanctum');
        $first = $this->postJson('/api/v1/addresses', $this->payload() + ['user_id' => $other->user_id, 'uuid' => $other->uuid, 'is_default' => false])
            ->assertCreated()->assertJsonPath('data.is_default', true)->assertJsonMissingPath('data.user_id')
            ->assertJsonMissingPath('data.deleted_at')->json('data.id');
        $this->assertTrue(Str::isUuid($first));
        $this->assertDatabaseHas('customer_addresses', ['uuid' => $first, 'user_id' => $user->id, 'is_default' => true]);
        $second = $this->postJson('/api/v1/addresses', $this->payload())->assertCreated()->assertJsonPath('data.is_default', false)->json('data.id');
        $third = $this->postJson('/api/v1/addresses', $this->payload() + ['is_default' => true])->assertCreated()->json('data.id');
        $this->assertSame(1, $user->addresses()->where('is_default', true)->count());
        $this->getJson('/api/v1/addresses')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.id', $third);
        $this->patchJson('/api/v1/addresses/'.$first.'/default')->assertOk()->assertJsonPath('data.is_default', true);
        $this->patchJson('/api/v1/addresses/'.$second, ['label' => 'العمل', 'is_default' => true])->assertOk()->assertJsonPath('data.label', 'العمل');
        $this->assertSame($second, $user->addresses()->where('is_default', true)->first()->uuid);
        $this->deleteJson('/api/v1/addresses/'.$second)->assertOk();
        $this->assertSoftDeleted('customer_addresses', ['uuid' => $second]);
        $this->assertSame($third, $user->addresses()->where('is_default', true)->first()->uuid);
        $this->getJson('/api/v1/addresses/'.$second)->assertNotFound();
        $this->deleteJson('/api/v1/addresses/'.$third)->assertOk();
        $this->deleteJson('/api/v1/addresses/'.$first)->assertOk();
        $this->assertSame(0, $user->addresses()->count());
        $this->assertModelExists($other);
    }

    #[DataProvider('ownedEndpoints')]
    public function test_foreign_addresses_return_404(string $method, string $suffix): void
    {
        $address = CustomerAddress::factory()->default()->create();
        $before = $address->fresh()->getAttributes();
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->json($method, '/api/v1/addresses/'.$address->uuid.$suffix, ['label' => 'محاولة'])->assertNotFound();
        $this->assertSame($before, $address->fresh()->getAttributes());
    }

    public static function ownedEndpoints(): array
    {
        return [['GET', ''], ['PATCH', ''], ['DELETE', ''], ['PATCH', '/default']];
    }

    #[DataProvider('invalidData')]
    public function test_invalid_addresses_are_rejected_without_persistence(array $data): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')->postJson('/api/v1/addresses', array_replace($this->payload(), $data))->assertUnprocessable();
        $this->assertDatabaseCount('customer_addresses', 0);
    }

    public static function invalidData(): array
    {
        return [[['recipient_name' => null]], [['phone' => null]], [['city' => null]], [['district' => null]],
            [['latitude' => 91, 'longitude' => 46]], [['latitude' => 24, 'longitude' => 181]],
            [['latitude' => 24]], [['longitude' => 46]], [['latitude' => 'bad', 'longitude' => 46]],
            [['notes' => str_repeat('x', 1001)]], [['is_default' => 'yes']]];
    }

    public function test_partial_updates_validate_final_coordinate_pair(): void
    {
        $address = CustomerAddress::factory()->withCoordinates()->create();
        $this->actingAs($address->user, 'sanctum');
        $url = '/api/v1/addresses/'.$address->uuid;
        $this->patchJson($url, ['label' => 'محدث'])->assertOk()->assertJsonPath('data.location.latitude', '24.7000000');
        $this->patchJson($url, ['latitude' => 25])->assertOk()->assertJsonPath('data.location.longitude', '46.7000000');
        $this->patchJson($url, ['latitude' => null])->assertUnprocessable()->assertJsonValidationErrors('latitude');
        $this->assertSame('25.0000000', $address->fresh()->latitude);
        $this->patchJson($url, ['latitude' => null, 'longitude' => null])->assertOk()->assertJsonPath('data.location.latitude', null);
        $this->patchJson($url, ['longitude' => 46])->assertUnprocessable();
    }

    private function payload(): array
    {
        return ['recipient_name' => 'عميل', 'phone' => '0501234567', 'city' => 'الرياض', 'district' => 'المصيف'];
    }

    public function test_database_prevents_multiple_live_default_addresses(): void
    {
        $user = User::factory()->create();
        CustomerAddress::factory()->forUser($user)->default()->create();
        $this->expectException(UniqueConstraintViolationException::class);
        CustomerAddress::factory()->forUser($user)->default()->create();
    }
}
