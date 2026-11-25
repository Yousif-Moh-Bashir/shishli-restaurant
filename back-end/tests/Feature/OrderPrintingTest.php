<?php

namespace Tests\Feature;

use App\Actions\Orders\TransitionOrderStatusAction;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusSource;
use App\Events\OrderStatusChanged;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemOption;
use App\Models\OrderStatusHistory;
use App\Models\PrintAgent;
use App\Models\PrintJob;
use App\Models\User;
use App\Services\Printing\AcceptedOrderPrinting;
use App\Services\Printing\PrintJobBroker;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OrderPrintingTest extends TestCase
{
    use DatabaseMigrations;

    private PrintAgent $agent;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        config(['printing.enabled' => true, 'printing.allow_live' => false, 'staff_notifications.enabled' => false]);
        $this->seed(RolePermissionSeeder::class);
        $this->manager = User::factory()->create()->assignRole('manager');
        $this->agent = PrintAgent::factory()->create();
        $this->withServerVariables(['HTTPS' => 'on']);
    }

    private function accepted(): Order
    {
        $order = Order::factory()->create(['branch_id' => $this->agent->branch_id]);

        return app(TransitionOrderStatusAction::class)->handle($order, OrderStatus::Confirmed, $this->manager, OrderStatusSource::Admin);
    }

    public function test_print_jobs_only_become_visible_after_outer_commit_and_not_after_rollback(): void
    {
        $order = Order::factory()->create(['branch_id' => $this->agent->branch_id]);
        DB::beginTransaction();
        app(TransitionOrderStatusAction::class)->handle($order, OrderStatus::Confirmed, $this->manager, OrderStatusSource::Admin);
        $this->assertDatabaseCount('print_jobs', 0);
        DB::rollBack();
        $this->assertDatabaseCount('print_jobs', 0);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        DB::beginTransaction();
        app(TransitionOrderStatusAction::class)->handle($order->fresh(), OrderStatus::Confirmed, $this->manager, OrderStatusSource::Admin);
        $this->assertDatabaseCount('print_jobs', 0);
        DB::commit();
        $this->assertDatabaseCount('print_jobs', 2);
    }

    public function test_duplicate_events_and_recovery_do_not_duplicate_originals(): void
    {
        $order = $this->accepted();
        for ($i = 0; $i < 3; $i++) {
            OrderStatusChanged::dispatch($order, OrderStatus::Pending, OrderStatus::Confirmed, $this->manager->id, OrderStatusSource::Admin);
            app(AcceptedOrderPrinting::class)->recover();
        }
        $this->assertDatabaseCount('print_jobs', 2);
        $this->expectException(QueryException::class);
        $copy = PrintJob::firstOrFail()->replicate(['uuid']);
        $copy->save();
    }

    public function test_system_confirmation_cannot_create_print_jobs_even_during_recovery(): void
    {
        $order = Order::factory()->create(['branch_id' => $this->agent->branch_id, 'status' => OrderStatus::Confirmed]);
        $history = $order->statusHistory()->create([
            'from_status' => OrderStatus::Pending,
            'to_status' => OrderStatus::Confirmed,
            'source' => OrderStatusSource::System,
            'changed_by' => $this->manager->id,
        ]);

        OrderStatusChanged::dispatch($order, OrderStatus::Pending, OrderStatus::Confirmed, $this->manager->id, OrderStatusSource::System);
        app(AcceptedOrderPrinting::class)->capture($history->id);
        $this->assertSame(0, app(AcceptedOrderPrinting::class)->recover());
        $this->assertDatabaseCount('print_jobs', 0);
    }

    public function test_recovery_handles_lost_event_without_printing_pre_activation_orders(): void
    {
        Event::fake([OrderStatusChanged::class]);
        $old = $this->accepted();
        $oldHistory = $old->statusHistory()->firstOrFail();
        $this->agent->update(['activation_history_id' => $oldHistory->id]);
        $new = $this->accepted();
        app(AcceptedOrderPrinting::class)->recover();
        $this->assertDatabaseCount('print_jobs', 2);
        $this->assertSame([$new->id], PrintJob::distinct()->pluck('order_id')->all());
    }

    public function test_capture_failure_does_not_break_accepted_order_and_is_recoverable(): void
    {
        $this->mock(AcceptedOrderPrinting::class)->shouldReceive('capture')->once()->andThrow(new \RuntimeException('private details'));
        $order = $this->accepted();
        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
        $this->assertDatabaseCount('print_jobs', 0);
        $this->app->forgetInstance(AcceptedOrderPrinting::class);
        app(AcceptedOrderPrinting::class)->recover();
        $this->assertDatabaseCount('print_jobs', 2);
    }

    public function test_snapshots_use_original_order_prices_and_minimize_kitchen_data(): void
    {
        $order = Order::factory()->delivery()->create(['branch_id' => $this->agent->branch_id, 'total' => '47.00', 'customer_notes' => 'بدون ملح']);
        $item = OrderItem::factory()->create(['order_id' => $order->id, 'product_name' => 'وجبة عربية', 'unit_price' => '20.00', 'quantity' => 2]);
        OrderItemOption::factory()->create(['order_item_id' => $item->id, 'option_value_name' => 'جبنة']);
        $order->refresh()->update(['total' => '47.00']);
        app(TransitionOrderStatusAction::class)->handle($order, OrderStatus::Confirmed, $this->manager, OrderStatusSource::Admin);
        $kitchen = PrintJob::where('destination', 'kitchen')->firstOrFail()->receipt;
        $cashier = PrintJob::where('destination', 'cashier')->firstOrFail()->receipt;
        $this->assertArrayNotHasKey('customer', $kitchen);
        $this->assertArrayNotHasKey('pricing', $kitchen['items'][0]);
        $this->assertSame('بدون ملح', $kitchen['notes']);
        $this->assertSame('جبنة', $kitchen['items'][0]['options'][0]['value']);
        $this->assertSame('47.00', $cashier['totals']['total']);
        $this->assertSame('20.00', $cashier['items'][0]['pricing']['unit_price']);
        $this->assertStringNotContainsString($order->customer_name, DB::table('print_jobs')->where('destination', 'cashier')->value('receipt'));
    }

    public function test_cancelled_orders_are_never_claimed_or_started(): void
    {
        $order = $this->accepted();
        $broker = app(PrintJobBroker::class);
        $claim = $broker->claim($this->agent, 'preview');
        app(TransitionOrderStatusAction::class)->handle($order, OrderStatus::Cancelled, $this->manager, OrderStatusSource::Admin, 'Customer cancelled');
        $this->assertFalse($broker->start($this->agent, $claim['job_id'], $claim['attempt_id'], 'preview')['allowed']);
        $this->assertNull($broker->claim($this->agent, 'preview'));
        $this->assertSame(['cancelled'], PrintJob::distinct()->pluck('status')->all());
    }

    public function test_leases_are_exclusive_and_one_printer_failure_does_not_repeat_the_other(): void
    {
        $this->accepted();
        $broker = app(PrintJobBroker::class);
        $a = $broker->claim($this->agent, 'preview');
        $b = $broker->claim($this->agent, 'preview');
        $this->assertNotSame($a['job_id'], $b['job_id']);
        $this->assertNull($broker->claim($this->agent, 'preview'));
        $broker->start($this->agent, $a['job_id'], $a['attempt_id'], 'preview');
        $broker->result($this->agent, $a['job_id'], $a['attempt_id'], 'simulated', null, null);
        $broker->result($this->agent, $b['job_id'], $b['attempt_id'], 'not_submitted', 'PRINTER_UNAVAILABLE', null);
        $this->travel(16)->seconds();
        $retry = $broker->claim($this->agent, 'preview');
        $this->assertSame($b['job_id'], $retry['job_id']);
        $this->assertNotSame($b['attempt_id'], $retry['attempt_id']);
    }

    public function test_expired_lease_can_retry_but_expired_execution_becomes_uncertain(): void
    {
        $this->accepted();
        $broker = app(PrintJobBroker::class);
        $first = $broker->claim($this->agent, 'preview');
        $this->travel(121)->seconds();
        $retry = $broker->claim($this->agent, 'preview');
        $this->assertSame($first['job_id'], $retry['job_id']);
        $broker->start($this->agent, $retry['job_id'], $retry['attempt_id'], 'preview');
        $this->travel(181)->seconds();
        $other = $broker->claim($this->agent, 'preview');
        $this->assertNotSame($retry['job_id'], $other['job_id']);
        $this->assertSame('uncertain', PrintJob::where('uuid', $retry['job_id'])->value('status'));
    }

    public function test_duplicate_acknowledgement_is_idempotent(): void
    {
        $this->accepted();
        $broker = app(PrintJobBroker::class);
        $claim = $broker->claim($this->agent, 'preview');
        $broker->start($this->agent, $claim['job_id'], $claim['attempt_id'], 'preview');
        $a = $broker->result($this->agent, $claim['job_id'], $claim['attempt_id'], 'simulated', null, null);
        $b = $broker->result($this->agent, $claim['job_id'], $claim['attempt_id'], 'simulated', null, null);
        $this->assertSame($a, $b);
        $this->assertDatabaseCount('print_job_attempts', 1);
    }

    public function test_agent_auth_requires_https_dedicated_token_and_active_unexpired_agent(): void
    {
        $path = 'https://localhost/api/v1/print-agent/claim';
        $token = 'spa_'.str_repeat('a', 64);
        $this->agent->update(['token_hash' => hash('sha256', $token)]);
        $this->postJson($path, ['mode' => 'preview'])->assertUnauthorized();
        $this->withToken($token)->postJson($path, ['mode' => 'preview'])->assertOk();
        $this->postJson('http://localhost/api/v1/print-agent/claim', ['mode' => 'preview'])->assertForbidden();
        $this->withServerVariables(['HTTPS' => 'on']);
        $this->agent->update(['expires_at' => now()->subSecond()]);
        $this->postJson($path, ['mode' => 'preview'])->assertUnauthorized();
        $this->agent->update(['expires_at' => now()->addDay(), 'enabled' => false]);
        $this->postJson($path, ['mode' => 'preview'])->assertUnauthorized();
    }

    public function test_connectivity_succeeds_with_printing_disabled_without_resolving_broker_or_changing_jobs(): void
    {
        $this->accepted();
        $token = 'spa_'.str_repeat('a', 64);
        $this->agent->update(['token_hash' => hash('sha256', $token)]);
        $agentBefore = $this->agent->fresh()->getRawOriginal();
        $jobsBefore = PrintJob::orderBy('id')->get()->map->getRawOriginal()->all();
        $this->app->bind(PrintJobBroker::class, function (): never {
            throw new \LogicException('Connectivity must not resolve PrintJobBroker.');
        });

        foreach ([false, true] as $enabled) {
            config(['printing.enabled' => $enabled, 'printing.allow_live' => false]);
            $this->withToken($token)->postJson('https://localhost/api/v1/print-agent/connectivity')
                ->assertOk()->assertExactJson(['success' => true, 'message' => 'Success', 'data' => ['connected' => true], 'errors' => null])
                ->assertHeader('Cache-Control', 'no-store, private');
            $this->assertSame($enabled, config('printing.enabled'));
            $this->assertFalse(config('printing.allow_live'));
        }

        $this->assertSame($agentBefore, $this->agent->fresh()->getRawOriginal());
        $this->assertSame($jobsBefore, PrintJob::orderBy('id')->get()->map->getRawOriginal()->all());
        $this->assertDatabaseCount('print_job_attempts', 0);
    }

    public function test_connectivity_requires_a_valid_dedicated_bearer_token_while_printing_is_disabled(): void
    {
        config(['printing.enabled' => false]);
        $path = 'https://localhost/api/v1/print-agent/connectivity';

        $this->postJson($path)->assertUnauthorized();
        foreach (['staff-token', 'spa_'.str_repeat('b', 64)] as $token) {
            $this->withToken($token)->postJson($path)->assertUnauthorized();
        }

        $this->assertDatabaseCount('print_jobs', 0);
        $this->assertDatabaseCount('print_job_attempts', 0);
        $this->assertNull($this->agent->fresh()->last_seen_at);
    }

    public function test_connectivity_rejects_expired_and_disabled_agents(): void
    {
        config(['printing.enabled' => false]);
        $token = 'spa_'.str_repeat('c', 64);
        $path = 'https://localhost/api/v1/print-agent/connectivity';
        $this->agent->update(['token_hash' => hash('sha256', $token), 'expires_at' => now()->subSecond()]);

        $this->withToken($token)->postJson($path)->assertUnauthorized();
        $this->agent->update(['expires_at' => now()->addDay(), 'enabled' => false]);
        $this->postJson($path)->assertUnauthorized();

        $this->assertNull($this->agent->fresh()->last_seen_at);
        $this->assertDatabaseCount('print_jobs', 0);
    }

    public function test_connectivity_rejects_insecure_http_even_when_printing_is_disabled(): void
    {
        config(['printing.enabled' => false]);
        $token = 'spa_'.str_repeat('d', 64);
        $this->agent->update(['token_hash' => hash('sha256', $token)]);

        $this->withToken($token)->postJson('http://localhost/api/v1/print-agent/connectivity')->assertForbidden();
        $this->withServerVariables(['HTTPS' => 'off', 'REMOTE_ADDR' => '192.0.2.40'])
            ->postJson('http://localhost/api/v1/print-agent/connectivity', [], [
                'X-Forwarded-Proto' => 'https',
                'Forwarded' => 'proto=https',
            ])->assertForbidden();

        $this->assertNull($this->agent->fresh()->last_seen_at);
        $this->assertDatabaseCount('print_jobs', 0);
    }

    public function test_connectivity_exception_does_not_enable_claim_start_or_result(): void
    {
        $this->accepted();
        config(['printing.enabled' => false, 'printing.allow_live' => false]);
        $token = 'spa_'.str_repeat('e', 64);
        $this->agent->update(['token_hash' => hash('sha256', $token)]);
        $this->withToken($token);
        $job = PrintJob::firstOrFail();
        $jobsBefore = PrintJob::orderBy('id')->get()->map->getRawOriginal()->all();
        $base = 'https://localhost/api/v1/print-agent';
        $attempt = (string) Str::uuid();

        $this->postJson($base.'/connectivity')->assertOk();
        $this->postJson($base.'/claim', ['mode' => 'preview'])->assertServiceUnavailable();
        $this->postJson($base.'/jobs/'.$job->uuid.'/start', ['attempt_id' => $attempt, 'mode' => 'preview'])->assertServiceUnavailable();
        $this->postJson($base.'/jobs/'.$job->uuid.'/result', ['attempt_id' => $attempt, 'outcome' => 'simulated'])->assertServiceUnavailable();

        $this->assertSame($jobsBefore, PrintJob::orderBy('id')->get()->map->getRawOriginal()->all());
        $this->assertDatabaseCount('print_job_attempts', 0);
        $this->assertNull($this->agent->fresh()->last_seen_at);
    }

    public function test_agent_cannot_access_admin_or_other_branch_jobs_or_live_mode(): void
    {
        $this->accepted();
        $other = PrintAgent::factory()->create();
        $token = 'spa_'.str_repeat('b', 64);
        $other->update(['token_hash' => hash('sha256', $token)]);
        $this->withToken($token)->getJson('/api/v1/admin/orders')->assertUnauthorized();
        $this->postJson('https://localhost/api/v1/print-agent/claim', ['mode' => 'live'])->assertConflict();
        $job = PrintJob::firstOrFail();
        $this->postJson('https://localhost/api/v1/print-agent/jobs/'.$job->uuid.'/start', ['attempt_id' => (string) Str::uuid(), 'mode' => 'preview'])->assertNotFound();
    }

    public function test_reprint_requires_separate_permission_and_idempotency_key(): void
    {
        $order = $this->accepted();
        $job = PrintJob::firstOrFail();
        $job->update(['status' => 'uncertain']);
        $path = '/api/v1/admin/orders/'.$order->uuid.'/print-jobs/'.$job->uuid.'/reprint';
        $body = ['operation_key' => (string) Str::uuid(), 'reason' => 'Checked the paper', 'acknowledge_duplicate_risk' => true];
        $cashier = User::factory()->create()->assignRole('cashier');
        $this->actingAs($cashier, 'sanctum')->postJson($path, $body)->assertForbidden();
        $this->actingAs($this->manager, 'sanctum')->postJson($path, $body)->assertOk();
        $this->postJson($path, $body)->assertOk();
        $this->assertDatabaseCount('print_jobs', 3);
        $this->getJson('/api/v1/admin/orders/'.$order->uuid.'/print-jobs')->assertOk()->assertJsonMissingPath('data.0.receipt');
    }

    public function test_register_sets_activation_boundary_and_token_rotation_preserves_it(): void
    {
        $old = Order::factory()->create();
        $history = OrderStatusHistory::factory()->create(['order_id' => $old->id]);
        $this->actingAs($this->manager, 'sanctum');
        $result = $this->postJson('/api/v1/admin/print-agents', ['branch_id' => $old->branch->uuid, 'name' => 'Local simulation'])->assertOk();
        $agent = PrintAgent::where('uuid', $result->json('data.agent.uuid'))->firstOrFail();
        $this->assertSame($history->id, $agent->activation_history_id);
        $this->assertSame(hash('sha256', $result->json('data.token')), $agent->token_hash);
        $this->patchJson('/api/v1/admin/print-agents/'.$agent->uuid, ['rotate_token' => true])->assertOk();
        $this->assertSame($history->id, $agent->fresh()->activation_history_id);
    }

    public function test_live_registration_requires_both_server_gates_and_excludes_older_acceptances(): void
    {
        $branchId = $this->agent->branch_id;
        $this->agent->delete();
        $old = Order::factory()->create(['branch_id' => $branchId]);
        app(TransitionOrderStatusAction::class)->handle($old, OrderStatus::Confirmed, $this->manager, OrderStatusSource::Admin);
        $body = ['branch_id' => $old->branch->uuid, 'name' => 'LOCAL LIVE FIXTURE', 'mode' => 'live'];
        $this->actingAs($this->manager, 'sanctum');
        $this->postJson('/api/v1/admin/print-agents', $body)->assertConflict();
        config(['printing.enabled' => false, 'printing.allow_live' => true]);
        $this->postJson('/api/v1/admin/print-agents', $body)->assertConflict();
        $this->assertDatabaseCount('print_agents', 0);

        config(['printing.enabled' => true]);
        $staff = User::factory()->create()->assignRole('kitchen');
        $this->actingAs($staff, 'sanctum')->postJson('/api/v1/admin/print-agents', $body)->assertForbidden();
        $result = $this->actingAs($this->manager, 'sanctum')->postJson('/api/v1/admin/print-agents', $body)->assertOk();
        $agent = PrintAgent::where('uuid', $result->json('data.agent.uuid'))->firstOrFail();
        $this->assertSame('live', $agent->mode);
        $this->assertSame($old->statusHistory()->firstOrFail()->id, $agent->activation_history_id);
        app(AcceptedOrderPrinting::class)->recover();
        $this->assertDatabaseCount('print_jobs', 0);

        $new = Order::factory()->create(['branch_id' => $branchId]);
        app(TransitionOrderStatusAction::class)->handle($new, OrderStatus::Confirmed, $this->manager, OrderStatusSource::Admin);
        $this->assertDatabaseCount('print_jobs', 2);
        $this->assertSame([$new->id], PrintJob::distinct()->pluck('order_id')->all());
        $this->withToken($result->json('data.token'))->postJson('https://localhost/api/v1/print-agent/claim', ['mode' => 'live'])
            ->assertOk()->assertJsonPath('data.mode', 'live');
    }

    public function test_existing_preview_agent_promotes_without_rotating_credentials_or_replaying_orders(): void
    {
        $this->freezeTime();
        $old = $this->accepted();
        $before = $this->agent->fresh();
        config(['printing.allow_live' => true]);

        $this->actingAs($this->manager, 'sanctum')->patchJson('/api/v1/admin/print-agents/'.$this->agent->uuid,
            ['mode' => 'live', 'confirm_new_orders_only' => true])->assertOk()
            ->assertJsonPath('data.agent.mode', 'live')->assertJsonPath('data.token', null)
            ->assertJsonMissingPath('data.agent.token_hash');

        $agent = $this->agent->fresh();
        foreach (['uuid', 'branch_id', 'name', 'token_hash', 'expires_at'] as $attribute) {
            $this->assertSame($before->getRawOriginal($attribute), $agent->getRawOriginal($attribute));
        }
        $this->assertSame($old->statusHistory()->firstOrFail()->id, $agent->activation_history_id);
        $this->assertSame(['review_required'], PrintJob::distinct()->pluck('status')->all());
        app(AcceptedOrderPrinting::class)->recover();
        $this->assertDatabaseCount('print_jobs', 2);
        $this->assertNull(app(PrintJobBroker::class)->claim($agent, 'live'));
        $this->assertSame(OrderStatus::Confirmed, $old->fresh()->status);
    }

    public function test_live_promotion_requires_permission_confirmation_and_both_server_gates(): void
    {
        $path = '/api/v1/admin/print-agents/'.$this->agent->uuid;
        $body = ['mode' => 'live', 'confirm_new_orders_only' => true];
        $before = $this->agent->fresh()->getRawOriginal();
        $this->patchJson($path, $body)->assertUnauthorized();
        $cashier = User::factory()->create()->assignRole('cashier');
        $this->actingAs($cashier, 'sanctum')->patchJson($path, $body)->assertForbidden();
        $this->actingAs($this->manager, 'sanctum')->patchJson($path, ['mode' => 'live'])->assertUnprocessable();
        $this->patchJson($path, ['mode' => 'live', 'confirm_new_orders_only' => false])->assertUnprocessable();
        foreach ([[false, false], [false, true], [true, false]] as [$enabled, $live]) {
            config(['printing.enabled' => $enabled, 'printing.allow_live' => $live]);
            $this->patchJson($path, $body)->assertConflict();
        }
        $this->assertSame($before, $this->agent->fresh()->getRawOriginal());
    }

    public function test_live_promotion_refuses_inactive_agents_inflight_jobs_and_credential_changes(): void
    {
        config(['printing.allow_live' => true]);
        $path = '/api/v1/admin/print-agents/'.$this->agent->uuid;
        $body = ['mode' => 'live', 'confirm_new_orders_only' => true];
        $this->actingAs($this->manager, 'sanctum');
        $this->agent->update(['enabled' => false]);
        $this->patchJson($path, $body)->assertConflict();
        $this->agent->update(['enabled' => true, 'expires_at' => now()->subDay()]);
        $this->patchJson($path, $body)->assertConflict();
        $this->agent->update(['expires_at' => now()->addDays(90)]);
        $this->patchJson($path, [...$body, 'rotate_token' => true])->assertConflict();
        $this->patchJson($path, [...$body, 'enabled' => true])->assertConflict();
        $this->accepted();
        $broker = app(PrintJobBroker::class);
        $claim = $broker->claim($this->agent, 'preview');
        $this->patchJson($path, $body)->assertConflict();
        $broker->start($this->agent, $claim['job_id'], $claim['attempt_id'], 'preview');
        $this->patchJson($path, $body)->assertConflict();
        $this->assertSame('preview', $this->agent->fresh()->mode);
        $this->assertSame('executing', PrintJob::where('uuid', $claim['job_id'])->value('status'));
    }

    public function test_repeated_live_promotion_preserves_boundary_and_new_pending_jobs(): void
    {
        config(['printing.allow_live' => true]);
        $path = '/api/v1/admin/print-agents/'.$this->agent->uuid;
        $body = ['mode' => 'live', 'confirm_new_orders_only' => true];
        $this->actingAs($this->manager, 'sanctum')->patchJson($path, $body)->assertOk();
        $before = $this->agent->fresh()->getRawOriginal();
        $this->travel(2)->seconds();
        $this->accepted();

        $this->patchJson($path, $body)->assertOk();

        $this->assertSame($before['activation_history_id'], $this->agent->fresh()->activation_history_id);
        $this->assertSame($before['activated_at'], $this->agent->fresh()->getRawOriginal('activated_at'));
        $this->assertSame(['pending'], PrintJob::distinct()->pluck('status')->all());
        $this->assertDatabaseCount('print_jobs', 2);
        $this->patchJson($path, ['mode' => 'preview'])->assertUnprocessable();
    }

    public function test_stale_preview_request_cannot_claim_jobs_after_live_promotion(): void
    {
        config(['printing.allow_live' => true]);
        $stale = $this->agent->fresh();
        $this->actingAs($this->manager, 'sanctum')->patchJson('/api/v1/admin/print-agents/'.$this->agent->uuid,
            ['mode' => 'live', 'confirm_new_orders_only' => true])->assertOk();
        $this->accepted();

        try {
            app(PrintJobBroker::class)->claim($stale, 'preview');
            $this->fail('A stale preview request must not claim a live job.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertDatabaseCount('print_job_attempts', 0);
    }

    public function test_admin_acceptance_to_two_live_results_is_idempotent_and_keeps_original_token(): void
    {
        config(['printing.allow_live' => true]);
        $token = 'spa_'.str_repeat('e', 64);
        $this->agent->update(['token_hash' => hash('sha256', $token)]);
        $assignment = BranchProduct::factory()->create(['branch_id' => $this->agent->branch_id]);
        $cart = $this->postJson('/api/v1/cart', ['branch_uuid' => $assignment->branch->uuid])->assertCreated()->json('data');
        $headers = ['X-Cart-Token' => $cart['token'], 'X-Cart-UUID' => $cart['id']];
        $this->postJson('/api/v1/cart/items', ['product_uuid' => $assignment->product->uuid, 'quantity' => 1], $headers)->assertCreated();
        $orderUuid = $this->postJson('/api/v1/checkout', [
            'type' => 'pickup',
            'payment_method' => 'cash',
            'customer' => ['name' => 'Customer', 'phone' => '0501234567'],
        ], $headers)->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->assertSame(0, app(AcceptedOrderPrinting::class)->recover());
        $this->assertDatabaseCount('print_jobs', 0);

        $this->actingAs($this->manager, 'sanctum')->patchJson('/api/v1/admin/print-agents/'.$this->agent->uuid,
            ['mode' => 'live', 'confirm_new_orders_only' => true])->assertOk();
        $path = '/api/v1/admin/orders/'.$orderUuid.'/confirm';
        $this->postJson($path)->assertOk();
        $this->postJson($path)->assertConflict();
        $this->assertDatabaseCount('print_jobs', 2);
        $base = 'https://localhost/api/v1/print-agent';
        $this->withToken($token);
        $destinations = [];
        foreach (['kitchen', 'cashier'] as $destination) {
            $claim = $this->postJson($base.'/claim', ['mode' => 'live'])->assertOk()->json('data');
            $destinations[] = $claim['destination'];
            $this->assertSame($destination, $claim['destination']);
            $this->assertSame(1, $claim['receipt']['version']);
            $this->assertSame('live', $claim['mode']);
            $start = ['attempt_id' => $claim['attempt_id'], 'mode' => 'live'];
            $this->postJson($base.'/jobs/'.$claim['job_id'].'/start', $start)->assertOk()->assertJsonPath('data.allowed', true);
            $result = ['attempt_id' => $claim['attempt_id'], 'outcome' => 'spooler_accepted'];
            $this->postJson($base.'/jobs/'.$claim['job_id'].'/result', $result)->assertOk()->assertJsonPath('data.status', 'spooler_accepted');
            $this->postJson($base.'/jobs/'.$claim['job_id'].'/result', $result)->assertOk();
        }
        $this->assertSame(['kitchen', 'cashier'], $destinations);
        $this->postJson($base.'/claim', ['mode' => 'live'])->assertOk()->assertJsonPath('data', null);
        app(AcceptedOrderPrinting::class)->recover();
        $this->assertDatabaseCount('print_jobs', 2);
        $this->assertDatabaseCount('print_job_attempts', 2);
        $this->assertSame(['spooler_accepted'], PrintJob::distinct()->pluck('status')->all());
    }

    public function test_mysql_concurrent_capture_and_claim_use_real_independent_connections(): void
    {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'shishli_printing_test') {
            $this->markTestSkipped('Requires the isolated MySQL printing test database.');
        }
        Event::fake([OrderStatusChanged::class]);
        $order = $this->accepted();
        $history = $order->statusHistory()->firstOrFail();
        $this->workers('capture', (string) $history->id);
        $this->assertDatabaseCount('print_jobs', 2);
        $claims = $this->workers('claim', $this->agent->uuid);
        $this->assertNotSame($claims[0]['job_id'], $claims[1]['job_id']);
        $this->assertDatabaseCount('print_job_attempts', 2);
        $this->assertNull(app(PrintJobBroker::class)->claim($this->agent, 'preview'));
    }

    public function test_unpaid_or_unauthorized_confirmation_creates_no_print_jobs(): void
    {
        $order = Order::factory()->create(['branch_id' => $this->agent->branch_id, 'payment_method' => 'card', 'payment_status' => 'pending']);
        $path = '/api/v1/admin/orders/'.$order->uuid.'/confirm';
        $kitchen = User::factory()->create()->assignRole('kitchen');
        $this->actingAs($kitchen, 'sanctum')->postJson($path)->assertForbidden();
        $this->actingAs($this->manager, 'sanctum')->postJson($path)->assertUnprocessable();
        $this->assertDatabaseCount('print_jobs', 0);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    public function test_stale_attempt_cannot_start_after_a_new_lease_and_old_jobs_require_review(): void
    {
        $this->accepted();
        $broker = app(PrintJobBroker::class);
        $first = $broker->claim($this->agent, 'preview');
        $this->travel(121)->seconds();
        $broker->claim($this->agent, 'preview');
        $token = 'spa_'.str_repeat('c', 64);
        $this->agent->update(['token_hash' => hash('sha256', $token)]);
        $this->withToken($token)->postJson('https://localhost/api/v1/print-agent/jobs/'.$first['job_id'].'/start',
            ['attempt_id' => $first['attempt_id'], 'mode' => 'preview'])->assertConflict();
        $this->travel(31)->minutes();
        $broker->expireLeases();
        $this->assertNull($broker->claim($this->agent, 'preview'));
        $this->assertSame(['review_required'], PrintJob::distinct()->pluck('status')->all());
    }

    public function test_retry_budget_is_bounded_and_scheduler_expires_execution_to_uncertain(): void
    {
        $this->accepted();
        $broker = app(PrintJobBroker::class);
        for ($i = 0; $i < 3; $i++) {
            $claim = $broker->claim($this->agent, 'preview');
            $broker->result($this->agent, $claim['job_id'], $claim['attempt_id'], 'not_submitted', 'PRINTER_UNAVAILABLE', null);
            $this->travel(46)->seconds();
        }
        $this->assertSame('failed', PrintJob::where('destination', 'kitchen')->value('status'));
        $other = $broker->claim($this->agent, 'preview');
        $broker->start($this->agent, $other['job_id'], $other['attempt_id'], 'preview');
        $this->travel(181)->seconds();
        app(AcceptedOrderPrinting::class)->recover();
        $this->assertSame('uncertain', PrintJob::where('uuid', $other['job_id'])->value('status'));
    }

    public function test_https_agent_contract_claim_start_and_result_are_idempotent_and_preview_only(): void
    {
        $this->accepted();
        $token = 'spa_'.str_repeat('d', 64);
        $this->agent->update(['token_hash' => hash('sha256', $token)]);
        $this->withToken($token);
        $base = 'https://localhost/api/v1/print-agent';
        $claim = $this->postJson($base.'/claim', ['mode' => 'preview'])->assertOk()->json('data');
        $this->assertSame('kitchen', $claim['destination']);
        $this->assertArrayNotHasKey('customer', $claim['receipt']);
        $start = ['attempt_id' => $claim['attempt_id'], 'mode' => 'preview'];
        $this->postJson($base.'/jobs/'.$claim['job_id'].'/start', $start)->assertOk()->assertJsonPath('data.allowed', true);
        $this->postJson($base.'/jobs/'.$claim['job_id'].'/start', $start)->assertOk()->assertJsonPath('data.allowed', true);
        $result = ['attempt_id' => $claim['attempt_id'], 'outcome' => 'spooler_accepted'];
        $this->postJson($base.'/jobs/'.$claim['job_id'].'/result', $result)->assertConflict();
        $result['outcome'] = 'simulated';
        $this->postJson($base.'/jobs/'.$claim['job_id'].'/result', $result)->assertOk()->assertJsonPath('data.status', 'simulated');
        $this->postJson($base.'/jobs/'.$claim['job_id'].'/result', $result)->assertOk()->assertJsonPath('data.status', 'simulated');
        $this->postJson($base.'/claim', ['mode' => 'preview'])->assertOk()->assertJsonPath('data.destination', 'cashier');
        $this->assertDatabaseCount('print_job_attempts', 2);
    }

    public function test_cancellation_after_start_revokes_further_start_permission_without_claiming_physical_outcome(): void
    {
        $order = $this->accepted();
        $broker = app(PrintJobBroker::class);
        $claim = $broker->claim($this->agent, 'preview');
        $this->assertTrue($broker->start($this->agent, $claim['job_id'], $claim['attempt_id'], 'preview')['allowed']);
        app(TransitionOrderStatusAction::class)->handle($order, OrderStatus::Cancelled, $this->manager, OrderStatusSource::Admin, 'Customer cancelled');
        $this->assertFalse($broker->start($this->agent, $claim['job_id'], $claim['attempt_id'], 'preview')['allowed']);
        $this->assertSame('executing', PrintJob::where('uuid', $claim['job_id'])->value('status'));
    }

    public function test_delayed_recovery_does_not_make_old_acceptance_look_like_a_new_print_request(): void
    {
        Event::fake([OrderStatusChanged::class]);
        $this->accepted();
        $this->travel(31)->minutes();
        app(AcceptedOrderPrinting::class)->recover();
        $this->assertDatabaseCount('print_jobs', 2);
        $this->assertSame(['review_required'], PrintJob::distinct()->pluck('status')->all());
        $this->assertNull(app(PrintJobBroker::class)->claim($this->agent, 'preview'));
    }

    private function workers(string $mode, string $argument): array
    {
        $processes = [];
        for ($i = 0; $i < 2; $i++) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, base_path('tests/Support/print-claim-worker.php'), $mode, $argument],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            $this->assertIsResource($process);
            $processes[] = [$process, $pipes];
        }
        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $error);
            $results[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        }

        return $results;
    }
}
