<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPromotion;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\User;
use App\Services\Reports\DashboardService;
use App\Services\Reports\ReportFilterData;
use App\Services\Reports\ReportMath;
use App\Services\Reports\ReportService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('manager');

        return $user;
    }

    private function url(string $report, array $filters = []): string
    {
        return '/api/v1/admin/reports/'.$report.'?'.http_build_query(array_merge(
            ['date_from' => '2026-10-01', 'date_to' => '2026-10-02'], $filters));
    }

    #[DataProvider('reportEndpoints')]
    public function test_report_endpoints_require_authentication_and_their_specific_permission(string $report, string $permission): void
    {
        $this->getJson($this->url($report))->assertUnauthorized();
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $this->actingAs($user)->getJson($this->url($report))->assertForbidden();
        $user->givePermissionTo($permission);

        $this->getJson($this->url($report))->assertOk();
    }

    public static function reportEndpoints(): array
    {
        return array_map(fn (string $report, string $permission): array => [$report, $permission],
            array_keys(ReportService::PERMISSIONS), array_values(ReportService::PERMISSIONS));
    }

    public function test_dashboard_permissions_and_financial_separation(): void
    {
        $this->getJson('/api/v1/admin/dashboard')->assertUnauthorized();
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/v1/admin/dashboard')->assertForbidden();
        $user->assignRole('cashier');

        $this->getJson('/api/v1/admin/dashboard')->assertOk()
            ->assertJsonMissingPath('data.summary.collected_revenue')
            ->assertJsonMissingPath('data.comparison.previous.collected_revenue')
            ->assertJsonMissingPath('data.comparison.changes.collected_revenue');
        $this->getJson($this->url('payments'))->assertForbidden();
    }

    public function test_dashboard_today_uses_riyadh_boundaries_and_exact_completed_sales(): void
    {
        $this->travelTo('2026-10-02 22:00:00');
        $admin = $this->admin();
        Order::factory()->completed()->create(['placed_at' => '2026-10-02 20:59:59', 'total' => '500.00']);
        Order::factory()->completed()->create(['placed_at' => '2026-10-02 21:00:00', 'total' => '0.10']);
        Order::factory()->completed()->delivery()->create(['placed_at' => '2026-10-03 20:59:59', 'total' => '0.20']);
        Order::factory()->cancelled()->create(['placed_at' => '2026-10-03 10:00:00', 'total' => '500.00']);
        Order::factory()->create(['placed_at' => '2026-10-03 10:00:00', 'total' => '500.00']);
        Order::factory()->completed()->create(['placed_at' => '2026-10-03 21:00:00', 'total' => '500.00']);
        User::factory()->customer()->create(['created_at' => '2026-10-02 21:00:00']);
        User::factory()->customer()->create(['created_at' => '2026-10-02 20:59:59']);

        $this->actingAs($admin)->getJson('/api/v1/admin/dashboard')->assertOk()
            ->assertJsonPath('data.period.from', '2026-10-03')->assertJsonPath('data.period.timezone', 'Asia/Riyadh')
            ->assertJsonPath('data.period.utc_from', '2026-10-02T21:00:00.000000Z')
            ->assertJsonPath('data.summary.orders_count', 4)->assertJsonPath('data.summary.completed_orders_count', 2)
            ->assertJsonPath('data.summary.cancelled_orders_count', 1)->assertJsonPath('data.summary.pending_orders_count', 1)
            ->assertJsonPath('data.summary.completed_sales', '0.30')->assertJsonPath('data.summary.average_order_value', '0.15')
            ->assertJsonPath('data.summary.new_customers_count', 1)->assertJsonPath('data.summary.guest_orders_count', 4)
            ->assertJsonPath('data.order_types.delivery', 1)->assertJsonPath('data.order_types.pickup', 3);
    }

    public function test_dashboard_comparison_uses_previous_equal_calendar_period(): void
    {
        $admin = $this->admin();
        Order::factory()->completed()->create(['placed_at' => '2026-09-29 22:00:00', 'total' => '10.00']);
        Order::factory()->completed()->create(['placed_at' => '2026-10-01 22:00:00', 'total' => '15.00']);

        $this->actingAs($admin)->getJson('/api/v1/admin/dashboard?date_from=2026-10-01&date_to=2026-10-02')->assertOk()
            ->assertJsonPath('data.comparison.period.from', '2026-09-29')->assertJsonPath('data.comparison.period.to', '2026-09-30')
            ->assertJsonPath('data.comparison.changes.completed_sales.percentage_change', '50.00');
    }

    #[DataProvider('changes')]
    public function test_percentage_changes_handle_growth_decline_and_zero(int $current, int $previous, ?string $percentage, string $trend): void
    {
        $this->assertSame(['percentage_change' => $percentage, 'trend' => $trend], ReportMath::comparison($current, $previous));
    }

    public static function changes(): array
    {
        return ['growth' => [150, 100, '50.00', 'up'], 'decline' => [50, 100, '-50.00', 'down'],
            'new' => [10, 0, null, 'up'], 'empty' => [0, 0, '0.00', 'flat'], 'disappeared' => [0, 10, '-100.00', 'down']];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_report_filters_return_422(array $filters, string $field): void
    {
        $this->actingAs($this->admin())->getJson($this->url('orders', $filters))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function invalidFilters(): array
    {
        return ['date' => [['date_from' => 'not-date'], 'date_from'],
            'reversed' => [['date_from' => '2026-10-03'], 'date_to'],
            'too long' => [['date_from' => '2025-01-01'], 'date_to'],
            'timezone override' => [['timezone' => 'UTC'], 'timezone'],
            'branch malformed' => [['branch_uuid' => 'invalid'], 'branch_uuid'],
            'branch missing' => [['branch_uuid' => '00000000-0000-0000-0000-000000000001'], 'branch_uuid'],
            'status' => [['order_status' => 'invalid'], 'order_status'], 'type' => [['order_type' => 'invalid'], 'order_type'],
            'method' => [['payment_method' => 'invalid'], 'payment_method'],
            'group injection' => [['group_by' => 'day; DROP TABLE orders'], 'group_by'],
            'sort injection' => [['sort_by' => 'password'], 'sort_by'],
            'page size' => [['per_page' => 101], 'per_page'], 'page' => [['page' => 0], 'page'],
            'xlsx unsupported' => [['format' => 'xlsx'], 'format']];
    }

    public function test_orders_report_filters_and_rates_use_all_matching_order_denominator(): void
    {
        $admin = $this->admin();
        $branch = Branch::factory()->create();
        Order::factory()->for($branch)->completed()->create(['placed_at' => '2026-10-01 12:00:00', 'total' => '10.00']);
        Order::factory()->for($branch)->cancelled()->create(['placed_at' => '2026-10-01 12:00:00']);
        Order::factory()->for($branch)->create(['placed_at' => '2026-10-01 12:00:00']);
        Order::factory()->for($branch)->completed()->delivery()->create(['placed_at' => '2026-10-01 12:00:00']);
        Order::factory()->completed()->create(['placed_at' => '2026-10-01 12:00:00', 'total' => '99.00']);

        $filters = ['branch_uuid' => $branch->uuid, 'order_type' => 'pickup', 'payment_method' => 'cash'];
        $this->actingAs($admin)->getJson($this->url('orders', $filters))->assertOk()
            ->assertJsonPath('data.summary.orders_count', 3)->assertJsonPath('data.summary.completion_rate', '33.33')
            ->assertJsonPath('data.summary.cancellation_rate', '33.33')->assertJsonPath('data.summary.completed_sales', '10.00');
        $this->getJson($this->url('orders', [...$filters, 'order_status' => 'completed', 'per_page' => 1]))->assertOk()->assertJsonCount(1, 'data.data');
    }

    #[DataProvider('trendGroups')]
    public function test_sales_trend_groups_business_dates_and_fills_missing_buckets(string $group, string $first, int $buckets): void
    {
        $admin = $this->admin();
        Order::factory()->completed()->create(['placed_at' => '2026-09-30 20:59:59', 'total' => '90.00']);
        Order::factory()->completed()->create(['placed_at' => '2026-09-30 21:00:00', 'total' => '1.25']);
        Order::factory()->cancelled()->create(['placed_at' => '2026-10-01 01:00:00', 'total' => '90.00']);

        $this->actingAs($admin)->getJson($this->url('sales/trend', ['date_to' => '2026-10-08', 'group_by' => $group]))->assertOk()
            ->assertJsonCount($buckets, 'data.data')->assertJsonPath('data.data.0.period', $first)
            ->assertJsonPath('data.data.0.orders_count', 2)->assertJsonPath('data.data.0.completed_sales', '1.25');
    }

    public static function trendGroups(): array
    {
        return [['day', '2026-10-01', 8], ['week', '2026-09-28', 2], ['month', '2026-10-01', 1]];
    }

    public function test_empty_summary_has_zero_average_and_zero_comparison(): void
    {
        $this->actingAs($this->admin())->getJson('/api/v1/admin/dashboard?date_from=2026-10-01&date_to=2026-10-02')->assertOk()
            ->assertJsonPath('data.summary.average_order_value', '0.00')
            ->assertJsonPath('data.comparison.changes.completed_sales.percentage_change', '0.00');
    }

    public function test_historical_branch_performance_includes_inactive_and_deleted_branches(): void
    {
        $admin = $this->admin();
        $branch = Branch::factory()->create(['is_active' => false]);
        Order::factory()->for($branch)->completed()->create(['placed_at' => '2026-10-01 12:00:00', 'total' => '12.34']);
        $branch->delete();
        Order::factory()->completed()->create(['placed_at' => '2026-10-01 12:00:00', 'total' => '999.00']);

        $this->actingAs($admin)->getJson($this->url('branches', ['branch_uuid' => $branch->uuid]))->assertOk()
            ->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.completed_sales', '12.34')
            ->assertJsonPath('data.data.0.average_order_value', '12.34');
    }

    #[DataProvider('productSorts')]
    public function test_product_reports_use_snapshot_amounts_and_distinct_orders_with_deleted_product_fallback(string $sort): void
    {
        $admin = $this->admin();
        $product = Product::factory()->create(['name' => 'Current']);
        $order = Order::factory()->completed()->create(['placed_at' => '2026-10-01 12:00:00']);
        foreach ([['Old snapshot', '10.00', '1.00'], ['Latest snapshot', '20.00', '2.00']] as [$name, $gross, $discount]) {
            OrderItem::factory()->for($order)->create(['product_id' => $product->id, 'product_uuid' => $product->uuid,
                'product_name' => $name, 'quantity' => 2, 'line_total' => $gross, 'discount_total' => $discount]);
        }
        OrderItem::factory()->for(Order::factory()->cancelled()->create(['placed_at' => '2026-10-01 12:00:00']))->create(['product_uuid' => $product->uuid]);

        $this->actingAs($admin)->getJson($this->url('products', ['sort_by' => $sort]))->assertOk()
            ->assertJsonPath('data.data.0.product_name', 'Current')->assertJsonPath('data.data.0.quantity_sold', 4)
            ->assertJsonPath('data.data.0.orders_count', 1)->assertJsonPath('data.data.0.gross_item_sales', '30.00')
            ->assertJsonPath('data.data.0.discount_total', '3.00')->assertJsonPath('data.data.0.net_item_sales', '27.00');
        $product->delete();
        $this->getJson($this->url('products'))->assertOk()->assertJsonPath('data.data.0.product_name', 'Latest snapshot');
    }

    public static function productSorts(): array
    {
        return [['quantity'], ['net_sales'], ['orders_count']];
    }

    public function test_category_reports_keep_snapshots_after_category_deletion_and_label_legacy_unknowns(): void
    {
        $admin = $this->admin();
        $category = Category::factory()->create(['name' => 'Historical']);
        $order = Order::factory()->completed()->create(['placed_at' => '2026-10-01 12:00:00']);
        OrderItem::factory()->for($order)->create(['category_uuid' => $category->uuid, 'category_name' => $category->name,
            'line_total' => '10.00', 'discount_total' => '2.00']);
        OrderItem::factory()->for($order)->create(['line_total' => '1.00']);
        $category->update(['name' => 'Renamed']);
        $category->delete();

        $this->actingAs($admin)->getJson($this->url('categories'))->assertOk()->assertJsonCount(2, 'data.data')
            ->assertJsonPath('data.data.0.category_name', 'Historical')->assertJsonPath('data.data.0.net_sales', '8.00')
            ->assertJsonPath('data.data.1.category_uuid', null);
    }

    public function test_payments_use_processing_dates_and_successful_sale_capture_without_attempt_or_authorization_double_counting(): void
    {
        $admin = $this->admin();
        $order = Order::factory()->create(['placed_at' => '2026-09-01 12:00:00', 'payment_method' => 'cash', 'payment_status' => 'paid']);
        $cash = Payment::factory()->for($order)->paid()->create(['created_at' => '2026-10-01 12:00:00']);
        PaymentTransaction::factory()->for($cash)->succeeded()->create(['amount' => '10.10', 'processed_at' => '2026-10-01 12:00:00', 'operation_key' => 'sale']);
        PaymentTransaction::factory()->for($cash)->failed()->create(['amount' => '99.00', 'processed_at' => '2026-10-01 12:00:00']);
        PaymentTransaction::factory()->for($cash)->succeeded()->create(['type' => 'authorization', 'amount' => '99.00', 'processed_at' => '2026-10-01 12:00:00']);
        PaymentTransaction::factory()->for($cash)->succeeded()->create(['amount' => '99.00', 'processed_at' => '2026-10-03 12:00:00']);
        PaymentTransaction::factory()->for($cash)->succeeded()->create(['amount' => '99.00', 'processed_at' => null, 'created_at' => '2026-10-01 12:00:00']);
        $online = Payment::factory()->online()->create(['created_at' => '2026-10-01 12:00:00']);
        PaymentTransaction::factory()->for($online)->succeeded()->create(['type' => 'capture', 'amount' => '10.10', 'processed_at' => '2026-10-01 12:00:00']);

        $this->actingAs($admin)->getJson($this->url('payments'))->assertOk()
            ->assertJsonPath('data.summary.payments_count', 2)->assertJsonPath('data.summary.successful_payments_count', 2)
            ->assertJsonPath('data.summary.failed_attempts_count', 1)->assertJsonPath('data.summary.collected_amount', '20.20')
            ->assertJsonPath('data.summary.refunds_supported', false)->assertJsonPath('data.summary.refunded_amount', null);
        $this->getJson('/api/v1/admin/dashboard?date_from=2026-10-01&date_to=2026-10-02')->assertOk()
            ->assertJsonPath('data.summary.collected_revenue', '20.20')->assertJsonPath('data.summary.completed_sales', '0.00');
    }

    public function test_promotion_and_coupon_reports_use_historical_snapshots_and_completed_orders(): void
    {
        $admin = $this->admin();
        $first = Order::factory()->completed()->create(['placed_at' => '2026-10-01 12:00:00', 'subtotal' => '100.00', 'discount_total' => '15.00']);
        $second = Order::factory()->completed()->create(['placed_at' => '2026-10-01 12:00:00', 'subtotal' => '50.00', 'discount_total' => '10.00']);
        $uuid = (string) Str::uuid();
        OrderPromotion::factory()->for($first)->create(['promotion_uuid' => $uuid, 'discount_amount' => '10.00', 'coupon_code' => 'OLD']);
        OrderPromotion::factory()->for($second)->create(['promotion_uuid' => $uuid, 'discount_amount' => '10.00', 'coupon_code' => 'OLD']);
        OrderPromotion::factory()->for($first)->create(['discount_amount' => '5.00']);
        OrderPromotion::factory()->for(Order::factory()->cancelled()->create(['placed_at' => '2026-10-01 12:00:00']))->create(['promotion_uuid' => $uuid]);

        $this->actingAs($admin)->getJson($this->url('promotions'))->assertOk()->assertJsonCount(2, 'data.data')
            ->assertJsonPath('data.data.0.usage_count', 2)->assertJsonPath('data.data.0.discount_total', '20.00')
            ->assertJsonPath('data.data.0.sales_before_discount', '150.00')->assertJsonPath('data.data.0.sales_after_discount', '125.00');
        $this->getJson($this->url('coupons'))->assertOk()->assertJsonPath('data.data.0.code', 'OLD')->assertJsonPath('data.data.0.orders_count', 2);
    }

    public function test_customer_report_excludes_staff_and_cancelled_spending_and_separates_guests(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->customer()->create(['created_at' => '2026-10-01 12:00:00']);
        $other = User::factory()->customer()->create(['created_at' => '2026-09-01 12:00:00']);
        Order::factory()->for($customer)->completed()->create(['placed_at' => '2026-10-01 12:00:00', 'total' => '10.00']);
        Order::factory()->for($customer)->cancelled()->create(['placed_at' => '2026-10-01 12:00:00', 'total' => '99.00']);
        Order::factory()->for($other)->create(['placed_at' => '2026-10-01 12:00:00']);
        Order::factory()->guest()->create(['placed_at' => '2026-10-01 12:00:00']);
        Order::factory()->for($admin)->create(['placed_at' => '2026-10-01 12:00:00']);

        $this->actingAs($admin)->getJson($this->url('customers'))->assertOk()
            ->assertJsonPath('data.summary.registered_customers_count', 2)->assertJsonPath('data.summary.new_registered_customers', 1)
            ->assertJsonPath('data.summary.customers_with_orders', 2)->assertJsonPath('data.summary.returning_customers_count', 1)
            ->assertJsonPath('data.summary.guest_orders_count', 1)->assertJsonPath('data.data.0.total_spent', '10.00');
    }

    public function test_delivery_fees_and_zone_snapshots_exclude_cancelled_fees_and_keep_zero_fee_orders(): void
    {
        $admin = $this->admin();
        $order = Order::factory()->delivery()->completed()->create(['placed_at' => '2026-10-01 12:00:00', 'delivery_fee' => '5.25']);
        $order->address->update(['delivery_zone_name' => 'Historical zone', 'delivery_zone_uuid' => (string) Str::uuid()]);
        Order::factory()->delivery()->completed()->create(['placed_at' => '2026-10-01 12:00:00', 'delivery_fee' => '0.00']);
        Order::factory()->delivery()->cancelled()->create(['placed_at' => '2026-10-01 12:00:00', 'delivery_fee' => '99.00']);
        Order::factory()->pickup()->create(['placed_at' => '2026-10-01 12:00:00']);

        $this->actingAs($admin)->getJson($this->url('delivery'))->assertOk()
            ->assertJsonPath('data.summary.delivery_orders_count', 3)->assertJsonPath('data.summary.pickup_orders_count', 1)
            ->assertJsonPath('data.summary.delivery_fees_collected', '5.25')->assertJsonPath('data.summary.free_delivery_orders_count', 1)
            ->assertJsonCount(2, 'data.data');
    }

    public function test_preparation_times_ignore_null_and_negative_intervals(): void
    {
        $admin = $this->admin();
        Order::factory()->create(['placed_at' => '2026-10-01 12:00:00', 'preparing_at' => '2026-10-01 12:00:00', 'ready_at' => '2026-10-01 12:10:00']);
        Order::factory()->create(['placed_at' => '2026-10-01 12:00:00', 'preparing_at' => '2026-10-01 12:00:00', 'ready_at' => '2026-10-01 12:20:00']);
        Order::factory()->create(['placed_at' => '2026-10-01 12:00:00']);
        Order::factory()->create(['placed_at' => '2026-10-01 12:00:00', 'preparing_at' => '2026-10-01 12:20:00', 'ready_at' => '2026-10-01 12:00:00']);

        $this->actingAs($admin)->getJson($this->url('order-statuses'))->assertOk()
            ->assertJsonPath('data.summary.sampled_orders_count', 2)->assertJsonPath('data.summary.average_preparation_minutes', '15.00');
    }

    public function test_cancellation_report_uses_exact_reasons_and_branch_filters(): void
    {
        $admin = $this->admin();
        $branch = Branch::factory()->create();
        Order::factory()->for($branch)->cancelled()->count(2)->create(['placed_at' => '2026-10-01 12:00:00', 'total' => '1.10', 'cancellation_reason' => 'Customer']);
        Order::factory()->cancelled()->create(['placed_at' => '2026-10-01 12:00:00', 'total' => '99.00']);

        $this->actingAs($admin)->getJson($this->url('cancellations', ['branch_uuid' => $branch->uuid]))->assertOk()
            ->assertJsonPath('data.summary.cancelled_orders_count', 2)->assertJsonPath('data.summary.cancelled_sales_value', '2.20')
            ->assertJsonPath('data.data.0.cancellation_reason', 'Customer');
    }

    #[DataProvider('exportReports')]
    public function test_exports_require_both_report_and_export_permissions(string $report, string $permission): void
    {
        $this->getJson($this->url($report.'/export'))->assertUnauthorized();
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create()->givePermissionTo('reports.export');
        $this->actingAs($user)->getJson($this->url($report.'/export'))->assertForbidden();
        $user->syncPermissions([$permission]);
        $this->getJson($this->url($report.'/export'))->assertForbidden();
        $user->givePermissionTo('reports.export');

        $response = $this->get($this->url($report.'/export'))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $response->streamedContent());
    }

    public static function exportReports(): array
    {
        return [['orders', 'reports.orders.view'], ['sales/trend', 'reports.sales.view'],
            ['products', 'reports.products.view'], ['payments', 'reports.payments.view'],
            ['customers', 'reports.customers.view'], ['promotions', 'reports.promotions.view']];
    }

    public function test_csv_export_preserves_arabic_decimal_amounts_filters_and_neutralizes_spreadsheet_formulas(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->customer()->create(['name' => '=HYPERLINK("evil") يوسف']);
        $order = Order::factory()->for($customer)->completed()->create(['placed_at' => '2026-10-01 12:00:00', 'total' => '0.10']);
        Order::factory()->for($customer)->cancelled()->create(['placed_at' => '2026-10-01 12:00:00', 'total' => '99.00']);
        User::factory()->customer()->create(['name' => 'Excluded']);

        $response = $this->actingAs($admin)->get($this->url('customers/export', ['branch_uuid' => $order->branch->uuid]))->assertOk();
        $csv = substr($response->streamedContent(), 3);
        $rows = array_map(fn (string $line): array => str_getcsv($line, ',', '"', ''), array_filter(explode("\n", trim($csv))));
        $this->assertSame(['customer_uuid', 'name', 'orders_count', 'completed_orders_count', 'total_spent', 'last_order_at'], $rows[0]);
        $this->assertSame("'".$customer->name, $rows[1][1]);
        $this->assertSame('0.10', $rows[1][4]);
        $this->assertCount(2, $rows);
    }

    public function test_streamed_exports_are_chunked_and_reject_an_excessive_row_count(): void
    {
        $admin = $this->admin();
        $branch = Branch::factory()->create();
        Order::factory()->for($branch)->count(501)->create(['placed_at' => '2026-10-01 12:00:00']);
        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $response = $this->actingAs($admin)->get($this->url('orders/export'))->assertOk();
            $content = $response->streamedContent();
            $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
            $this->assertStringContainsString('limit 500', strtolower($queries));
            $this->assertCount(502, array_filter(explode("\n", trim(substr($content, 3)))));
        } finally {
            DB::disableQueryLog();
        }
        config(['business.export_max_rows' => 500]);
        $this->getJson($this->url('orders/export'))->assertUnprocessable();
    }

    public function test_dashboard_and_report_query_counts_do_not_grow_with_entities(): void
    {
        $admin = $this->admin();
        $filter = new ReportFilterData('2026-10-01', '2026-10-02');
        $first = User::factory()->customer()->create();
        OrderItem::factory()->for(Order::factory()->for($first)->completed()->create(['placed_at' => '2026-10-01 12:00:00']))->create();
        $measure = function () use ($filter): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                app(DashboardService::class)->get($filter, true);
                app(ReportService::class)->get('products', $filter);
                app(ReportService::class)->get('customers', $filter);

                return count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
            }
        };
        $small = $measure();
        $customers = User::factory()->customer()->count(20)->create();
        foreach ($customers as $customer) {
            OrderItem::factory()->for(Order::factory()->for($customer)->completed()->create(['placed_at' => '2026-10-01 12:00:00']))->create();
        }

        $this->assertLessThanOrEqual($small, $measure());
        $this->assertSame(6, $this->dashboardQueryCount($filter));
    }

    private function dashboardQueryCount(ReportFilterData $filter): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            app(DashboardService::class)->get($filter, true);

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_checkout_records_category_snapshots_and_does_not_accept_frontend_category_overrides(): void
    {
        $assignment = BranchProduct::factory()->create(['price_override' => null]);
        $category = $assignment->product->category;
        $originalName = $category->name;
        $cart = $this->postJson('/api/v1/cart', ['branch_uuid' => $assignment->branch->uuid])->assertCreated();
        $this->withHeader('X-Cart-Token', $cart->json('data.token'));
        $this->postJson('/api/v1/cart/items', ['product_uuid' => $assignment->product->uuid, 'quantity' => 1])->assertCreated();

        $this->postJson('/api/v1/checkout', ['type' => 'pickup', 'payment_method' => 'cash',
            'customer' => ['name' => 'Customer', 'phone' => '0501234567'], 'category_uuid' => (string) Str::uuid()])->assertCreated();

        $this->assertDatabaseHas('order_items', ['category_uuid' => $category->uuid, 'category_name' => $category->name]);
        $category->update(['name' => 'Changed']);
        $this->assertDatabaseHas('order_items', ['category_uuid' => $category->uuid, 'category_name' => $originalName]);
    }

    public function test_report_defaults_and_dst_calendar_boundaries_are_explicit(): void
    {
        $this->travelTo('2026-10-03 12:00:00');
        $filter = ReportFilterData::fromArray([]);
        $this->assertSame('2026-09-04', $filter->dateFrom);
        $this->assertSame('2026-10-03', $filter->dateTo);
        config(['business.timezone' => 'America/New_York']);
        $dst = new ReportFilterData('2026-03-08', '2026-03-08');
        $this->assertSame('2026-03-08T05:00:00.000000Z', $dst->from->toISOString());
        $this->assertSame('2026-03-09T04:00:00.000000Z', $dst->until->toISOString());
        $this->assertSame('2026-03-07', $dst->previous()->dateFrom);
    }

    public function test_large_reporting_totals_and_average_are_not_limited_by_cart_money_maximum(): void
    {
        $admin = $this->admin();
        Order::factory()->completed()->count(2)->create(['placed_at' => '2026-10-01 12:00:00', 'total' => '9999999999.99']);

        $this->actingAs($admin)->getJson($this->url('orders'))->assertOk()
            ->assertJsonPath('data.summary.completed_sales', '19999999999.98')
            ->assertJsonPath('data.summary.average_order_value', '9999999999.99');
    }

    public function test_pending_payments_without_transactions_appear_in_method_breakdown(): void
    {
        $admin = $this->admin();
        Payment::factory()->pending()->create(['created_at' => '2026-10-01 12:00:00']);

        $this->actingAs($admin)->getJson($this->url('payments'))->assertOk()
            ->assertJsonPath('data.summary.payments_count', 1)->assertJsonPath('data.summary.collected_amount', '0.00')
            ->assertJsonPath('data.data.0.method', 'cash')->assertJsonPath('data.data.0.status', 'pending')
            ->assertJsonPath('data.data.0.transactions_count', 0)->assertJsonPath('data.data.0.collected_amount', '0.00');
    }

    public function test_financial_reports_and_exports_respect_branch_even_for_old_orders(): void
    {
        $admin = $this->admin();
        $branch = Branch::factory()->create();
        $order = Order::factory()->for($branch)->create(['placed_at' => '2026-01-01']);
        $payment = Payment::factory()->for($order)->create(['created_at' => '2026-01-01']);
        $transaction = PaymentTransaction::factory()->for($payment)->succeeded()->create(['amount' => '12.34', 'processed_at' => '2026-10-01 12:00:00']);
        $other = PaymentTransaction::factory()->succeeded()->create(['amount' => '99.00', 'processed_at' => '2026-10-01 12:00:00']);

        $this->actingAs($admin)->getJson($this->url('payments', ['branch_uuid' => $branch->uuid]))->assertOk()
            ->assertJsonPath('data.summary.collected_amount', '12.34')->assertJsonCount(1, 'data.data');
        $response = $this->get($this->url('payments/export', ['branch_uuid' => $branch->uuid]))->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString($transaction->uuid, $csv);
        $this->assertStringNotContainsString($other->uuid, $csv);
    }

    public function test_period_limit_allows_366_days_and_returns_zero_daily_buckets(): void
    {
        $this->actingAs($this->admin())->getJson($this->url('sales/trend', ['date_from' => '2025-10-02', 'date_to' => '2026-10-02']))->assertOk()
            ->assertJsonCount(366, 'data.data')->assertJsonPath('data.data.0.completed_sales', '0.00');
    }

    public function test_invalid_future_start_with_default_end_returns_422(): void
    {
        $this->travelTo('2026-10-03 12:00:00');

        $this->actingAs($this->admin())->getJson('/api/v1/admin/reports/orders?date_from=2026-10-05')
            ->assertUnprocessable()->assertJsonValidationErrors('date_to');
    }

    public function test_live_product_rename_and_price_change_never_change_snapshot_sales(): void
    {
        $admin = $this->admin();
        $product = Product::factory()->create();
        $order = Order::factory()->completed()->create(['placed_at' => '2026-10-01 12:00:00']);
        OrderItem::factory()->for($order)->create(['product_id' => $product->id, 'product_uuid' => $product->uuid,
            'product_name' => 'Historical name', 'line_total' => '12.34', 'discount_total' => '1.00']);
        $product->update(['name' => 'Renamed current product', 'base_price' => '999.00']);

        $this->actingAs($admin)->getJson($this->url('products'))->assertOk()
            ->assertJsonPath('data.data.0.product_name', 'Renamed current product')
            ->assertJsonPath('data.data.0.gross_item_sales', '12.34')->assertJsonPath('data.data.0.net_item_sales', '11.34');
    }

    public function test_status_breakdown_contains_all_operational_stage_counts(): void
    {
        $admin = $this->admin();
        foreach (OrderStatus::cases() as $status) {
            Order::factory()->create(['status' => $status, 'placed_at' => '2026-10-01 12:00:00']);
        }

        $response = $this->actingAs($admin)->getJson('/api/v1/admin/dashboard?date_from=2026-10-01&date_to=2026-10-02')->assertOk();
        foreach (OrderStatus::cases() as $status) {
            $response->assertJsonPath('data.order_statuses.'.$status->value, 1);
        }
    }
}
