<?php

namespace Tests\Feature;

use App\Actions\Branches\ReplaceBusinessHoursAction;
use App\Models\Banner;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\GalleryItem;
use App\Models\OptionGroup;
use App\Models\Order;
use App\Models\OutboundNotification;
use App\Models\Page;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Promotion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminListIntegrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->actingAs(User::factory()->create()->assignRole('super_admin'), 'sanctum');
    }

    public function test_order_filters_search_customer_branch_and_dates_combine_without_leaking_rows(): void
    {
        $customer = User::factory()->create()->assignRole('customer');
        $branch = Branch::factory()->create();
        $wanted = Order::factory()->for($branch)->for($customer, 'user')->create(['customer_name' => 'Search Me', 'status' => 'confirmed', 'payment_status' => 'paid', 'payment_method' => 'cash', 'placed_at' => '2026-10-03 12:00:00']);
        Order::factory()->create(['customer_name' => 'Search Me']);
        $query = http_build_query(['search' => 'Search', 'status' => 'confirmed', 'payment_status' => 'paid', 'payment_method' => 'cash', 'branch_uuid' => $branch->uuid, 'customer_uuid' => $customer->uuid, 'date_from' => '2026-10-03', 'date_to' => '2026-10-03', 'sort' => 'order_number', 'direction' => 'asc', 'per_page' => 1]);
        $this->getJson('/api/v1/admin/orders?'.$query)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $wanted->uuid)->assertJsonPath('meta.total', 1);
    }

    public function test_order_number_filter_and_legacy_branch_alias_remain_supported(): void
    {
        $order = Order::factory()->create();
        $this->getJson('/api/v1/admin/orders?branch='.$order->branch->uuid.'&order_number='.$order->order_number)->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_search_percent_and_underscore_are_literal(): void
    {
        Order::factory()->create(['customer_name' => 'Literal%_name']);
        Order::factory()->create(['customer_name' => 'Literal other name']);
        $this->getJson('/api/v1/admin/orders?search=%25_')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_order_counts_include_event_dates_in_restaurant_timezone(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-03 12:00:00', 'UTC'));
        Order::factory()->confirmed()->create();
        $completed = Order::factory()->completed()->create(['placed_at' => now()->subDay()]);
        $completed->forceFill(['completed_at' => now()])->save();
        $cancelled = Order::factory()->cancelled()->create();
        $cancelled->forceFill(['cancelled_at' => now()])->save();
        $this->getJson('/api/v1/admin/orders/counts')->assertOk()->assertJsonPath('data.confirmed', 1)->assertJsonPath('data.completed_today', 0)->assertJsonPath('data.cancelled_today', 1)->assertJsonPath('data.ready', 0);
    }

    public function test_admin_orders_kitchen_and_counts_use_each_branch_current_workday_and_support_history(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-06 03:00', 'Asia/Riyadh'));
        $early = Branch::factory()->withoutBusinessHours()->create();
        $late = Branch::factory()->withoutBusinessHours()->create();
        foreach ([[$early, '02:00'], [$late, '04:00']] as [$branch, $close]) {
            app(ReplaceBusinessHoursAction::class)->handle($branch, [
                ['day_of_week' => 1, 'opens_at' => '12:00', 'closes_at' => $close],
                ['day_of_week' => 2, 'opens_at' => '12:00', 'closes_at' => $close],
            ]);
        }
        $old = Order::factory()->for($early)->create(['status' => 'confirmed', 'placed_at' => CarbonImmutable::parse('2026-10-06 01:59:59', 'Asia/Riyadh')->utc()]);
        $current = Order::factory()->for($early)->create(['status' => 'confirmed', 'placed_at' => CarbonImmutable::parse('2026-10-06 02:00:00', 'Asia/Riyadh')->utc()]);
        $carry = Order::factory()->for($late)->create(['status' => 'preparing', 'placed_at' => CarbonImmutable::parse('2026-10-05 23:00', 'Asia/Riyadh')->utc()]);
        foreach (['/api/v1/admin/orders', '/api/v1/admin/kitchen/orders'] as $path) {
            $response = $this->getJson($path)->assertOk()->assertJsonCount(2, 'data');
            $this->assertEqualsCanonicalizing([$current->uuid, $carry->uuid], array_column($response->json('data'), 'id'));
            $this->getJson($path.'?period=history')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $old->uuid);
            $this->getJson($path.'?period=range&branch='.$early->uuid.'&date_from=2026-10-05&date_to=2026-10-05')
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $old->uuid);
            $this->getJson($path.'?period=range')->assertUnprocessable();
        }
        $this->getJson('/api/v1/admin/orders/counts')->assertOk()->assertJsonPath('data.confirmed', 1)->assertJsonPath('data.preparing', 1);
        $this->getJson('/api/v1/admin/orders/counts?period=history')->assertOk()->assertJsonPath('data.confirmed', 1)->assertJsonPath('data.preparing', 0);
        $this->getJson('/api/v1/admin/orders/'.$old->uuid)->assertOk();
    }

    #[DataProvider('orderActions')]
    public function test_available_actions_follow_state_type_permissions_and_payment(string $role, string $status, string $type, string $method, string $paid, array $expected): void
    {
        $this->actingAs(User::factory()->create()->assignRole($role), 'sanctum');
        $order = Order::factory()->create(['status' => $status, 'type' => $type, 'payment_method' => $method, 'payment_status' => $paid]);
        $response = $this->getJson('/api/v1/admin/orders/'.$order->uuid)->assertOk();
        $this->assertSame($expected, $response->json('data.available_actions'));
    }

    public static function orderActions(): array
    {
        return [
            ['manager', 'pending', 'pickup', 'cash', 'pending', ['confirm', 'cancel']], ['manager', 'pending', 'pickup', 'card', 'pending', ['cancel']], ['manager', 'pending', 'pickup', 'card', 'paid', ['confirm', 'cancel']],
            ['kitchen', 'confirmed', 'pickup', 'cash', 'pending', ['start_preparing']], ['kitchen', 'preparing', 'delivery', 'cash', 'pending', ['mark_ready']], ['cashier', 'pending', 'pickup', 'cash', 'pending', ['confirm']],
            ['manager', 'ready', 'delivery', 'cash', 'pending', ['cancel']], ['manager', 'ready', 'pickup', 'cash', 'pending', ['complete', 'cancel']], ['cashier', 'ready', 'pickup', 'cash', 'pending', ['complete']],
            ['cashier', 'out_for_delivery', 'delivery', 'cash', 'pending', []], ['manager', 'out_for_delivery', 'delivery', 'cash', 'pending', []], ['manager', 'completed', 'pickup', 'cash', 'paid', []], ['manager', 'cancelled', 'pickup', 'cash', 'pending', []]];
    }

    public function test_collect_cash_action_requires_matching_pending_cash_payment(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('cashier'), 'sanctum');
        $payment = Payment::factory()->create();
        $this->getJson('/api/v1/admin/orders/'.$payment->order->uuid)->assertOk()->assertJsonPath('data.available_actions', ['confirm', 'collect_cash']);
    }

    public function test_collect_cash_actions_follow_the_first_pending_payment_selected_by_existing_action(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('cashier'), 'sanctum');
        $order = Order::factory()->create(['total' => '85.00']);
        Payment::factory()->for($order)->create(['amount' => '1.00']);
        Payment::factory()->for($order)->create();
        $this->getJson('/api/v1/admin/orders/'.$order->uuid)->assertOk()->assertJsonPath('data.available_actions', ['confirm']);
        $response = $this->postJson('/api/v1/admin/orders/'.$order->uuid.'/payments/cash/collect')->assertUnprocessable()->assertJsonPath('errors.payment', ['PAYMENT_AMOUNT_MISMATCH']);
        $this->assertTrue(Str::isUuid($response->headers->get('X-Request-ID')));
    }

    public function test_invalid_transition_retains_field_error_and_business_code(): void
    {
        $order = Order::factory()->completed()->create();
        $this->postJson('/api/v1/admin/orders/'.$order->uuid.'/confirm')->assertUnprocessable()->assertJsonValidationErrors('status')->assertJsonPath('errors.code', ['INVALID_ORDER_TRANSITION']);
    }

    public function test_kitchen_order_detail_and_operations_hide_financial_and_contact_information(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('kitchen'), 'sanctum');
        $order = Order::factory()->confirmed()->create();
        $data = $this->getJson('/api/v1/admin/orders/'.$order->uuid)->assertOk()->json('data');
        foreach (['pricing', 'customer', 'payment', 'latest_payment', 'address'] as $key) {
            $this->assertArrayNotHasKey($key, $data);
        }
        $data = $this->postJson('/api/v1/admin/orders/'.$order->uuid.'/start-preparing')->assertOk()->json('data');
        $this->assertArrayNotHasKey('pricing', $data);
        $this->assertArrayNotHasKey('customer', $data);
    }

    public function test_order_detail_reuses_snapshots_history_and_payments(): void
    {
        $order = Order::factory()->create();
        Payment::factory()->for($order)->create();
        $this->getJson('/api/v1/admin/orders/'.$order->uuid)->assertOk()->assertJsonStructure(['data' => ['items', 'promotions', 'address', 'payment', 'latest_payment', 'status_history', 'timeline', 'available_actions']]);
    }

    public function test_product_alias_filters_branch_category_and_inventory_tracking(): void
    {
        $product = Product::factory()->featured()->create(['name' => 'Turkish Chicken', 'tracks_inventory' => true]);
        $branch = Branch::factory()->create();
        $product->branches()->attach($branch->id, ['is_available' => true]);
        Product::factory()->create();
        $query = http_build_query(['search' => 'Turkish', 'category_uuid' => $product->category->uuid, 'branch_uuid' => $branch->uuid, 'active' => 1, 'available' => 1, 'featured' => 1, 'tracks_inventory' => 1, 'sort' => 'base_price', 'direction' => 'desc']);
        $this->getJson('/api/v1/admin/products?'.$query)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $product->uuid);
    }

    public function test_products_list_loads_primary_image_only_and_detail_has_full_relations(): void
    {
        $product = Product::factory()->create();
        ProductImage::factory()->for($product)->create(['is_primary' => true]);
        ProductImage::factory()->for($product)->create(['is_primary' => false]);
        BranchInventory::factory()->for($product)->create();
        $data = $this->getJson('/api/v1/admin/products')->assertOk()->json('data.0');
        $this->assertNotNull($data['primary_image']);
        $this->assertArrayNotHasKey('images', $data);
        $this->assertArrayNotHasKey('option_groups', $data);
        $this->assertArrayNotHasKey('inventory_summary', $data);
        $this->getJson('/api/v1/admin/products/'.$product->uuid)->assertOk()->assertJsonCount(2, 'data.images')->assertJsonCount(1, 'data.inventory_summary')->assertJsonStructure(['data' => ['branches', 'option_groups']]);
    }

    public function test_product_detail_hides_inventory_balances_without_inventory_permission(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('cashier'), 'sanctum');
        $inventory = BranchInventory::factory()->create();
        $this->getJson('/api/v1/admin/products/'.$inventory->product->uuid)->assertOk()->assertJsonMissingPath('data.inventory_summary');
    }

    public function test_option_group_search_active_alias_sort_and_pagination(): void
    {
        $group = OptionGroup::factory()->create(['name' => 'Find Options', 'is_active' => true]);
        OptionGroup::factory()->create(['name' => 'Other Options', 'is_active' => false]);
        $this->getJson('/api/v1/admin/option-groups?search=Find&active=1&sort=name&direction=asc&per_page=1')->assertOk()->assertJsonPath('data.0.id', $group->uuid)->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/admin/option-groups?sort=unknown')->assertUnprocessable()->assertJsonValidationErrors('sort');
    }

    public function test_notification_search_sort_and_pagination_use_safe_existing_resource(): void
    {
        $notification = OutboundNotification::factory()->create(['recipient' => 'find@example.com']);
        OutboundNotification::factory()->create(['recipient' => 'other@example.com']);
        $this->getJson('/api/v1/admin/notifications?search=find&sort=created_at&direction=desc&per_page=1')->assertOk()->assertJsonPath('data.0.id', $notification->uuid)->assertJsonPath('meta.total', 1)->assertJsonMissingPath('data.0.content');
        $this->getJson('/api/v1/admin/notifications?sort=unknown')->assertUnprocessable()->assertJsonValidationErrors('sort');
    }

    public function test_coupon_search_active_alias_sort_and_pagination(): void
    {
        $promotion = Promotion::factory()->coupon()->create();
        $coupon = Coupon::factory()->for($promotion)->create(['code' => 'FIND123', 'is_active' => true]);
        Coupon::factory()->for($promotion)->create(['code' => 'OTHER123', 'is_active' => false]);
        $path = '/api/v1/admin/promotions/'.$promotion->uuid.'/coupons';
        $this->getJson($path.'?search=FIND&active=1&sort=code&direction=asc&per_page=1')->assertOk()->assertJsonPath('data.0.id', $coupon->uuid)->assertJsonPath('meta.total', 1);
        $this->getJson($path.'?sort=unknown')->assertUnprocessable()->assertJsonValidationErrors('sort');
    }

    public function test_branch_product_name_sort_and_category_availability_aliases(): void
    {
        $branch = Branch::factory()->create();
        $first = Product::factory()->create(['name' => 'Z Product']);
        $second = Product::factory()->create(['name' => 'A Product']);
        BranchProduct::factory()->for($branch)->for($first)->create(['is_available' => true]);
        BranchProduct::factory()->for($branch)->for($second)->create(['is_available' => false]);
        $path = '/api/v1/admin/branches/'.$branch->uuid.'/products';
        $this->getJson($path.'?sort=product_name&direction=asc')->assertOk()->assertJsonPath('data.0.product.id', $second->uuid);
        $this->getJson($path.'?category_uuid='.$first->category->uuid.'&available=1')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson($path.'?sort=unknown')->assertUnprocessable()->assertJsonValidationErrors('sort');
    }

    public function test_branch_products_load_tracked_inventory_in_bulk(): void
    {
        $branch = Branch::factory()->create();
        $first = BranchInventory::factory()->for($branch)->create();
        BranchProduct::factory()->for($branch)->for($first->product)->create(['is_available' => true]);
        $path = '/api/v1/admin/branches/'.$branch->uuid.'/products';
        $this->getJson($path)->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson($path)->assertOk()->assertJsonPath('data.0.effective_is_available', true);
        $small = count(DB::getQueryLog());
        DB::disableQueryLog();
        foreach (BranchInventory::factory()->count(5)->for($branch)->create() as $inventory) {
            BranchProduct::factory()->for($branch)->for($inventory->product)->create(['is_available' => true]);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson($path)->assertOk()->assertJsonCount(6, 'data');
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual($small + 1, $large);
    }

    public function test_category_tree_contains_counts_and_nested_uuid_children(): void
    {
        $root = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $root->id]);
        Product::factory()->for($child)->create();
        $this->getJson('/api/v1/admin/categories/tree')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $root->uuid)->assertJsonPath('data.0.children.0.id', $child->uuid)->assertJsonPath('data.0.children.0.products_count', 1);
    }

    public function test_branch_detail_includes_hours_availability_and_counts(): void
    {
        $branch = Branch::factory()->create();
        $this->getJson('/api/v1/admin/branches/'.$branch->uuid)->assertOk()->assertJsonPath('data.products_count', 0)->assertJsonPath('data.delivery_zones_count', 0)->assertJsonStructure(['data' => ['business_hours' => ['days'], 'availability' => ['is_open', 'pickup', 'delivery']]]);
    }

    public function test_customer_aliases_filter_active_registration_dates(): void
    {
        $customer = User::factory()->create(['is_active' => false, 'created_at' => '2026-10-03 12:00:00'])->assignRole('customer');
        User::factory()->create()->assignRole('customer');
        $this->getJson('/api/v1/admin/customers?active=0&date_from=2026-10-03&date_to=2026-10-03')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $customer->uuid);
    }

    public function test_payment_search_filter_and_safe_sort(): void
    {
        $payment = Payment::factory()->create();
        Payment::factory()->create();
        $this->getJson('/api/v1/admin/payments?search='.$payment->order->order_number.'&method=cash&status=pending&sort=amount&direction=asc')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $payment->uuid);
    }

    #[DataProvider('promotionStates')]
    public function test_promotions_filter_schedule_state(string $state): void
    {
        $wanted = match ($state) {
            'active' => Promotion::factory()->create(['name' => 'Find Offer']), 'future' => Promotion::factory()->future()->create(['name' => 'Find Offer']), 'expired' => Promotion::factory()->expired()->create(['name' => 'Find Offer']), 'inactive' => Promotion::factory()->inactive()->create(['name' => 'Find Offer'])
        };
        Promotion::factory()->create(['name' => 'Other']);
        $this->getJson('/api/v1/admin/promotions?search=Find&status='.$state.'&sort=name')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $wanted->uuid);
    }

    public static function promotionStates(): array
    {
        return [['active'], ['future'], ['expired'], ['inactive']];
    }

    #[DataProvider('cmsLists')]
    public function test_cms_admin_search_sort_and_pagination(string $screen, string $model): void
    {
        $wanted = $model::factory()->create(['title' => 'Search Item']);
        $model::factory()->create(['title' => 'Other']);
        $this->getJson('/api/v1/admin/'.$screen.'?search=Search&sort=title&direction=asc&per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $wanted->uuid)->assertJsonPath('meta.total', 1);
    }

    public static function cmsLists(): array
    {
        return [['pages', Page::class], ['banners', Banner::class], ['gallery', GalleryItem::class]];
    }

    public function test_settings_grouped_endpoint_preserves_flat_settings_contract(): void
    {
        $flat = $this->getJson('/api/v1/admin/settings')->assertOk()->json('data');
        $grouped = $this->getJson('/api/v1/admin/settings/groups')->assertOk()->json('data');
        $this->assertSame(count($flat), collect($grouped)->flatten(1)->count());
        $this->assertArrayHasKey('general', $grouped);
    }

    #[DataProvider('paginatedLists')]
    public function test_defaults_and_maximum_page_size_follow_standard(string $screen, int $default): void
    {
        $this->getJson('/api/v1/admin/'.$screen)->assertOk()->assertJsonPath('meta.per_page', $default);
        $this->getJson('/api/v1/admin/'.$screen.'?per_page=100')->assertOk()->assertJsonPath('meta.per_page', 100);
    }

    public static function paginatedLists(): array
    {
        return [['orders', 15], ['products', 20], ['categories', 20], ['branches', 20], ['customers', 20], ['payments', 15], ['promotions', 20], ['staff', 20], ['pages', 20], ['banners', 20], ['gallery', 20], ['activity-logs', 20]];
    }

    #[DataProvider('performanceLists')]
    public function test_list_query_count_does_not_grow_with_rows(string $screen): void
    {
        match ($screen) {
            'orders','dashboard' => Order::factory()->create(), 'products' => Product::factory()->create(), 'customers' => User::factory()->create()->assignRole('customer')
        };
        $this->getJson('/api/v1/admin/'.$screen)->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/admin/'.$screen)->assertOk();
        $small = count(DB::getQueryLog());
        DB::disableQueryLog();
        match ($screen) {
            'orders','dashboard' => Order::factory()->count(8)->create(), 'products' => Product::factory()->count(8)->create(), 'customers' => User::factory()->count(8)->create()->each(fn (User $u) => $u->assignRole('customer'))
        };
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/admin/'.$screen)->assertOk();
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual($small + 1, $large, 'Query count must stay bounded as rows increase.');
    }

    public static function performanceLists(): array
    {
        return [['orders'], ['products'], ['customers'], ['dashboard']];
    }
}
