<?php

namespace Tests\Feature;

use App\Models\AdminActivityLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use App\Services\Admin\AdminActivityService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminOperationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function actor(string $role = 'super_admin'): User
    {
        $user = User::factory()->create()->assignRole($role);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    #[DataProvider('bulkOperations')]
    public function test_explicit_bulk_product_updates_are_atomic_and_audited(string $operation, string $field): void
    {
        $actor = $this->actor();
        $products = Product::factory()->count(2)->create([$field => true]);
        $uuids = $products->pluck('uuid')->all();
        $this->postJson('/api/v1/admin/products/bulk/'.$operation, ['product_uuids' => $uuids, $field => false])->assertOk()->assertJsonPath('data.updated_count', 2);
        foreach ($products as $product) {
            $this->assertFalse($product->fresh()->{$field});
        } $log = AdminActivityLog::sole();
        $this->assertSame($actor->id, $log->user_id);
        $this->assertSame($uuids, $log->new_values['product_uuids']);
    }

    public static function bulkOperations(): array
    {
        return [['status', 'is_active'], ['availability', 'is_available']];
    }

    public function test_bulk_invalid_uuid_rolls_back_every_product(): void
    {
        $this->actor();
        $product = Product::factory()->create();
        $this->postJson('/api/v1/admin/products/bulk/status', ['product_uuids' => [$product->uuid, (string) Str::uuid()], 'is_active' => false])->assertUnprocessable()->assertJsonValidationErrors('product_uuids.1');
        $this->assertTrue($product->fresh()->is_active);
        $this->assertDatabaseCount('admin_activity_logs', 0);
    }

    public function test_bulk_maximum_is_100(): void
    {
        $this->actor();
        $this->postJson('/api/v1/admin/products/bulk/status', ['product_uuids' => array_map(fn () => (string) Str::uuid(), range(1, 101)), 'is_active' => false])->assertUnprocessable()->assertJsonValidationErrors('product_uuids');
    }

    public function test_bulk_rejects_duplicate_uuids_deleted_products_and_model_injection(): void
    {
        $this->actor();
        $product = Product::factory()->create();
        $this->postJson('/api/v1/admin/products/bulk/status', ['product_uuids' => [$product->uuid, $product->uuid], 'is_active' => false])->assertUnprocessable();
        $this->postJson('/api/v1/admin/products/bulk/status', ['product_uuids' => [$product->uuid], 'is_active' => false, 'model' => 'User', 'action' => 'delete'])->assertUnprocessable()->assertJsonValidationErrors(['model', 'action']);
        $product->delete();
        $this->postJson('/api/v1/admin/products/bulk/status', ['product_uuids' => [$product->uuid], 'is_active' => false])->assertUnprocessable();
        $this->assertDatabaseCount('admin_activity_logs', 0);
    }

    public function test_bulk_permission_cannot_be_bypassed(): void
    {
        $this->actor('cashier');
        $this->postJson('/api/v1/admin/products/bulk/status', ['product_uuids' => [], 'is_active' => false])->assertForbidden();
    }

    public function test_bulk_audit_failure_rolls_back_updates(): void
    {
        $this->actor();
        $product = Product::factory()->create();
        $audit = $this->mock(AdminActivityService::class);
        $audit->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $this->postJson('/api/v1/admin/products/bulk/status', ['product_uuids' => [$product->uuid], 'is_active' => false])->assertStatus(500);
        $this->assertTrue($product->fresh()->is_active);
    }

    public function test_activity_excludes_secrets_recursively_and_records_public_actor_and_subject(): void
    {
        $actor = $this->actor();
        $uuid = (string) Str::uuid();
        app(AdminActivityService::class)->record($actor, 'security.test', 'user', $uuid, [], ['name' => ['password' => 'nested-secret'], 'password' => 'raw-password', 'token' => 'raw-token', 'permissions' => ['orders.view', ['api_key' => 'private']], 'roles' => ['cashier'], 'is_active' => true]);
        $json = $this->getJson('/api/v1/admin/activity-logs')->assertOk()->assertJsonPath('data.0.actor.id', $actor->uuid)->assertJsonPath('data.0.subject.id', $uuid)->json('data.0');
        $this->assertCount(3, $json['new_values']);
        $this->assertSame(['orders.view'], $json['new_values']['permissions']);
        $this->assertSame(['cashier'], $json['new_values']['roles']);
        $this->assertTrue($json['new_values']['is_active']);
        $encoded = json_encode($json);
        foreach (['nested-secret', 'raw-password', 'raw-token', 'private', 'ip_hash', 'user_id'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
    }

    public function test_activity_filters_by_actor_action_subject_and_dates(): void
    {
        $actor = $this->actor();
        AdminActivityLog::factory()->for($actor, 'actor')->create(['action' => 'staff.create', 'subject_type' => 'user', 'created_at' => '2026-10-03 12:00:00']);
        AdminActivityLog::factory()->create(['action' => 'staff.update', 'created_at' => '2026-10-02 12:00:00']);
        $this->getJson('/api/v1/admin/activity-logs?user_uuid='.$actor->uuid.'&action=staff.create&subject_type=user&date_from=2026-10-03&date_to=2026-10-03&per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1);
    }

    public function test_reads_do_not_create_audit_records(): void
    {
        $this->actor();
        $this->getJson('/api/v1/admin/bootstrap')->assertOk();
        $this->getJson('/api/v1/admin/products')->assertOk();
        $this->assertDatabaseCount('admin_activity_logs', 0);
    }

    public function test_activity_is_protected_from_manager_and_kitchen(): void
    {
        $this->actor('manager');
        $this->getJson('/api/v1/admin/activity-logs')->assertForbidden();
        $this->actor('kitchen');
        $this->getJson('/api/v1/admin/activity-logs')->assertForbidden();
    }

    public function test_manual_cash_collection_is_logged_once_and_uses_existing_ledger(): void
    {
        $actor = $this->actor('cashier');
        $payment = Payment::factory()->create();
        $order = $payment->order;
        $this->postJson('/api/v1/admin/orders/'.$order->uuid.'/payments/cash/collect')->assertOk()->assertJsonPath('data.status', 'paid');
        $this->assertDatabaseHas('admin_activity_logs', ['user_id' => $actor->id, 'subject_uuid' => $order->uuid, 'action' => 'orders.payments.cash.collect']);
        $this->assertDatabaseCount('admin_activity_logs', 1);
        $this->postJson('/api/v1/admin/orders/'.$order->uuid.'/payments/cash/collect')->assertOk();
        $this->assertDatabaseCount('admin_activity_logs', 1);
    }

    public function test_order_cancellation_uses_existing_action_and_safe_audit(): void
    {
        $this->actor();
        $order = Order::factory()->create();
        $this->postJson('/api/v1/admin/orders/'.$order->uuid.'/cancel', ['reason' => 'Customer requested'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertDatabaseHas('admin_activity_logs', ['subject_uuid' => $order->uuid, 'action' => 'orders.cancel']);
        $this->assertSame(1, $order->statusHistory()->count());
    }

    public function test_promotion_update_is_audited_without_loading_usages(): void
    {
        $this->actor();
        $promotion = Promotion::factory()->create();
        $this->patchJson('/api/v1/admin/promotions/'.$promotion->uuid, ['name' => 'Updated Offer'])->assertOk();
        $this->assertDatabaseHas('admin_activity_logs', ['subject_uuid' => $promotion->uuid, 'action' => 'promotions.update']);
    }

    public function test_activity_records_are_immutable(): void
    {
        $log = AdminActivityLog::factory()->create();
        $this->expectException(\LogicException::class);
        $log->update(['action' => 'changed']);
    }

    public function test_activity_search_and_business_date_bounds(): void
    {
        $this->actor();
        $log = AdminActivityLog::factory()->create(['action' => 'staff.create', 'created_at' => '2026-10-02 22:00:00']);
        AdminActivityLog::factory()->create(['action' => 'staff.update', 'created_at' => '2026-10-03 12:00:00']);
        $this->getJson('/api/v1/admin/activity-logs?search=staff.create&date_from=2026-10-03&date_to=2026-10-03&sort=created_at&direction=asc')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $log->uuid);
    }
}
