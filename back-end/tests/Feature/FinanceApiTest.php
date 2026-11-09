<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Finance\AuditLog;
use App\Models\Finance\Expense;
use App\Models\Finance\ExpenseCategory;
use App\Models\Finance\Purchase;
use App\Models\Finance\RevenueDay;
use App\Models\Finance\RevenueSource;
use App\Models\Finance\Supplier;
use App\Models\User;
use App\Services\Finance\ReportExport;
use Carbon\CarbonImmutable;
use Database\Seeders\FinanceSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FinanceApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('testing', app()->environment());
        if (config('database.default') === 'mysql') {
            $this->assertSame('finance-mysql', config('database.connections.mysql.host'));
            $this->assertSame('shishli_finance_test', config('database.connections.mysql.database'));
            $this->assertEmpty(config('database.connections.mysql.url'));
        } else {
            $this->assertSame('sqlite', config('database.default'));
            $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        }
    }

    private function actor(string $role = 'finance_manager'): User
    {
        $this->beforeRefreshingDatabase();
        $this->seed(FinanceSeeder::class);
        $actor = User::factory()->create()->assignRole($role);
        $this->authenticateFinance($actor);

        return $actor;
    }

    private function authenticateFinance(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($user->createToken('finance', ['finance'], now()->addHours(8))->plainTextToken);
    }

    private function revenue(Branch $branch, string $amount = '100.10'): array
    {
        return ['branch_uuid' => $branch->uuid, 'date' => now()->toDateString(), 'notes' => 'اختبار', 'lines' => [
            ['source_uuid' => RevenueSource::firstOrFail()->uuid, 'amount' => $amount],
            ['source_uuid' => RevenueSource::orderBy('id')->skip(1)->firstOrFail()->uuid, 'amount' => '0.20'],
        ]];
    }

    private function outflow(Branch $branch, string $kind): array
    {
        $data = ['branch_uuid' => $branch->uuid, 'date' => now()->toDateString(), 'amount' => '20.25', 'description' => 'دفعة مسجلة', 'payment_method' => 'cash'];

        return $kind === 'expenses' ? [...$data, 'category_uuid' => ExpenseCategory::firstOrFail()->uuid] : [...$data, 'supplier_uuid' => Supplier::create(['name' => 'المورد', 'is_active' => true])->uuid, 'invoice_number' => 'INV-1'];
    }

    public function test_every_finance_endpoint_requires_authentication(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/finance/') || ! in_array('auth:sanctum', $route->gatherMiddleware(), true)) {
                continue;
            }
            $uri = '/'.str_replace(['{kind}', '{uuid}', '{format}'], ['expenses', '11111111-1111-4111-8111-111111111111', 'pdf'], $route->uri());
            if (str_contains($uri, '/settings/expenses')) {
                $uri = str_replace('/settings/expenses', '/settings/sources', $uri);
            }
            $this->json($route->methods()[0], $uri)->assertUnauthorized();
        }
    }

    public function test_finance_accounts_cannot_access_restaurant_admin(): void
    {
        $actor = $this->actor();
        $this->getJson('/api/v1/admin/me')->assertForbidden();
        $this->getJson('/api/v1/admin/orders')->assertForbidden();
        $this->assertFalse($actor->can('orders.view'));
        $this->assertFalse($actor->can('products.manage'));
    }

    public function test_restaurant_manager_does_not_receive_finance_access(): void
    {
        $this->seed(RolePermissionSeeder::class);
        Sanctum::actingAs(User::factory()->create()->assignRole('manager'));
        $this->getJson('/api/v1/finance/me')->assertForbidden();
    }

    public function test_revenue_total_is_calculated_on_server_and_day_cannot_be_duplicated(): void
    {
        $this->actor();
        $branch = Branch::factory()->create();
        $data = [...$this->revenue($branch), 'total' => '9999', 'status' => 'approved', 'approved_by' => 1];
        $response = $this->postJson('/api/v1/finance/revenues', $data)->assertCreated()->assertJsonPath('data.total', '100.30')->assertJsonPath('data.status', 'draft');
        $this->assertDatabaseHas('finance_revenue_days', ['uuid' => $response->json('data.uuid'), 'total' => '100.30', 'approved_by' => null]);
        $this->postJson('/api/v1/finance/revenues', $data)->assertUnprocessable()->assertJsonStructure(['errors' => ['date']]);
        $this->assertDatabaseCount('finance_revenue_days', 1);
    }

    public static function optionalRevenueAmounts(): array
    {
        return [
            'cash only' => ['150', '', '150.00'],
            'cash and network' => ['100', '200', '300.00'],
            'explicit zero and positive' => ['0', '0.75', '0.75'],
        ];
    }

    #[DataProvider('optionalRevenueAmounts')]
    public function test_optional_channels_are_saved_as_zero_without_changing_positive_amounts(string $cash, string $network, string $total): void
    {
        $this->actor();
        $branch = Branch::factory()->create();
        $sources = RevenueSource::all()->keyBy('code');
        $data = ['branch_uuid' => $branch->uuid, 'date' => now()->toDateString(), 'lines' => [
            ['source_uuid' => $sources['cash']->uuid, 'amount' => $cash],
            ['source_uuid' => $sources['network']->uuid, 'amount' => $network],
            ['source_uuid' => $sources['keeta']->uuid, 'amount' => ''],
            ['source_uuid' => $sources['hungerstation']->uuid, 'amount' => null],
            ['source_uuid' => $sources['jahez']->uuid],
        ]];
        $uuid = $this->postJson('/api/v1/finance/revenues', $data)->assertCreated()->assertJsonPath('data.total', $total)->json('data.uuid');
        $day = RevenueDay::where('uuid', $uuid)->firstOrFail();
        $this->assertSame($total, $day->total);
        $this->assertDatabaseHas('finance_revenue_lines', ['revenue_day_id' => $day->id, 'source_id' => $sources['keeta']->id, 'amount' => '0.00']);
        $this->assertDatabaseCount('finance_audit_logs', 1);
        $this->putJson('/api/v1/finance/revenues/'.$uuid, [...$data, 'version' => 1])->assertOk()->assertJsonPath('data.total', $total);
        $this->assertSame(5, $day->lines()->count());
    }

    public function test_all_blank_or_zero_revenues_are_rejected_without_writing(): void
    {
        $this->actor();
        $branch = Branch::factory()->create();
        foreach (['', null, '0', '0.00'] as $amount) {
            $data = $this->revenue($branch);
            foreach ($data['lines'] as &$line) {
                $line['amount'] = $amount;
            }
            unset($line);
            $this->postJson('/api/v1/finance/revenues', $data)->assertUnprocessable()->assertJsonValidationErrors('lines')
                ->assertJsonPath('errors.lines.0', 'أدخل مبلغ إيراد موجبًا في وسيلة واحدة على الأقل.');
        }
        $this->assertDatabaseCount('finance_revenue_days', 0);
        $this->assertDatabaseCount('finance_revenue_lines', 0);
        $this->assertDatabaseCount('finance_audit_logs', 0);
    }

    public function test_zero_total_edit_does_not_erase_existing_revenue(): void
    {
        $this->actor();
        $branch = Branch::factory()->create();
        $data = $this->revenue($branch);
        $uuid = $this->postJson('/api/v1/finance/revenues', $data)->assertCreated()->json('data.uuid');
        $empty = array_map(fn (array $line): array => [...$line, 'amount' => ''], $data['lines']);
        $this->putJson('/api/v1/finance/revenues/'.$uuid, [...$data, 'lines' => $empty, 'version' => 1])->assertUnprocessable()->assertJsonValidationErrors('lines');
        $this->assertDatabaseHas('finance_revenue_days', ['uuid' => $uuid, 'total' => '100.30', 'version' => 1]);
        $this->assertDatabaseCount('finance_revenue_lines', 2);
        $this->assertDatabaseCount('finance_audit_logs', 1);
    }

    public function test_approval_locks_normal_edits_and_correction_keeps_full_audit(): void
    {
        $actor = $this->actor();
        $branch = Branch::factory()->create();
        $data = $this->revenue($branch);
        $uuid = $this->postJson('/api/v1/finance/revenues', $data)->json('data.uuid');
        $this->postJson("/api/v1/finance/revenues/$uuid/approve", ['version' => 1])->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.approver.uuid', $actor->uuid);
        $this->postJson("/api/v1/finance/revenues/$uuid/approve", ['version' => 2])->assertConflict();
        $this->putJson("/api/v1/finance/revenues/$uuid", [...$data, 'version' => 2])->assertConflict();
        $corrected = [...$this->revenue($branch, '150.10'), 'version' => 2, 'reason' => 'تصحيح مبلغ الشبكة'];
        $this->postJson("/api/v1/finance/revenues/$uuid/correct", $corrected)->assertOk()->assertJsonPath('data.total', '150.30')->assertJsonPath('data.version', 3);
        $log = AuditLog::where('action', 'revenues.correct')->firstOrFail();
        $this->assertSame('100.30', $log->old_values['total']);
        $this->assertSame('150.30', $log->new_values['total']);
        $this->assertSame('100.10', $log->old_values['lines'][0]['amount']);
        $this->assertSame('تصحيح مبلغ الشبكة', $log->reason);
        $this->assertDatabaseCount('finance_audit_logs', 3);
    }

    public function test_stale_versions_and_undocumented_corrections_are_rejected(): void
    {
        $this->actor();
        $data = $this->revenue(Branch::factory()->create());
        $uuid = $this->postJson('/api/v1/finance/revenues', $data)->json('data.uuid');
        $this->putJson("/api/v1/finance/revenues/$uuid", [...$data, 'version' => 1])->assertOk();
        $this->putJson("/api/v1/finance/revenues/$uuid", [...$data, 'version' => 1])->assertConflict();
        $this->postJson("/api/v1/finance/revenues/$uuid/approve", ['version' => 2])->assertOk();
        $this->postJson("/api/v1/finance/revenues/$uuid/correct", [...$data, 'version' => 3])->assertUnprocessable()->assertJsonStructure(['errors' => ['reason']]);
    }

    public static function invalidAmounts(): array
    {
        return ['negative' => ['-1.00'], 'precision' => ['1.001'], 'exponent' => ['1e3'], 'overflow' => ['1000000000000.00'], 'nan' => ['NaN'], 'array' => [[]]];
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_amounts_never_write_financial_records(mixed $amount): void
    {
        $this->actor();
        $data = $this->revenue(Branch::factory()->create());
        $data['lines'][0]['amount'] = $amount;
        $this->postJson('/api/v1/finance/revenues', $data)->assertUnprocessable();
        $this->assertDatabaseCount('finance_revenue_days', 0);
        $this->assertDatabaseCount('finance_audit_logs', 0);
    }

    public function test_inactive_and_duplicate_sources_are_rejected(): void
    {
        $this->actor();
        $data = $this->revenue(Branch::factory()->create());
        RevenueSource::firstOrFail()->update(['is_active' => false]);
        $this->postJson('/api/v1/finance/revenues', $data)->assertUnprocessable();
        $data['lines'][0]['source_uuid'] = $data['lines'][1]['source_uuid'];
        $this->postJson('/api/v1/finance/revenues', $data)->assertUnprocessable();
        $this->assertDatabaseCount('finance_revenue_days', 0);
    }

    public function test_entry_user_is_scoped_to_assigned_branches_and_cannot_approve_or_export(): void
    {
        $this->actor();
        $branch = Branch::factory()->create();
        $data = $this->revenue($branch);
        $uuid = $this->postJson('/api/v1/finance/revenues', $data)->json('data.uuid');
        $entry = User::factory()->create()->assignRole('finance_entry');
        $this->authenticateFinance($entry);
        $this->getJson("/api/v1/finance/revenues/$uuid")->assertNotFound();
        $this->postJson('/api/v1/finance/revenues', $this->revenue(Branch::factory()->create()))->assertNotFound();
        $this->getJson('/api/v1/finance/revenues')->assertOk()->assertJsonCount(0, 'data');
        DB::table('finance_branch_user')->insert(['user_id' => $entry->id, 'branch_id' => $branch->id]);
        $this->getJson("/api/v1/finance/revenues/$uuid")->assertOk();
        $this->postJson("/api/v1/finance/revenues/$uuid/approve", ['version' => 1])->assertForbidden();
        $this->getJson('/api/v1/finance/reports/export/xlsx')->assertForbidden();
        $this->postJson('/api/v1/finance/settings/sources', ['name' => 'New', 'is_active' => true])->assertForbidden();
    }

    public function test_outflows_are_separate_and_duplicate_purchase_invoice_is_rejected(): void
    {
        $this->actor();
        $branch = Branch::factory()->create();
        $expense = $this->outflow($branch, 'expenses');
        $purchase = $this->outflow($branch, 'purchases');
        $this->postJson('/api/v1/finance/expenses', $expense)->assertCreated()->assertJsonPath('data.amount', '20.25');
        $this->postJson('/api/v1/finance/purchases', $purchase)->assertCreated();
        $this->postJson('/api/v1/finance/purchases', $purchase)->assertUnprocessable()->assertJsonStructure(['errors' => ['invoice_number']]);
        $this->assertDatabaseCount('finance_expenses', 1);
        $this->assertDatabaseCount('finance_purchases', 1);
        $this->assertDatabaseCount('orders', 0);
    }

    public static function emptyPurchaseSuppliers(): array
    {
        return ['blank' => [''], 'null' => [null], 'omitted' => ['omit']];
    }

    #[DataProvider('emptyPurchaseSuppliers')]
    public function test_purchase_invoice_number_and_description_are_optional(?string $value): void
    {
        $this->actor();
        $data = $this->outflow(Branch::factory()->create(), 'purchases');
        if ($value === 'omit') {
            unset($data['invoice_number'], $data['description']);
        } else {
            $data['invoice_number'] = $value;
            $data['description'] = $value;
        }
        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/v1/finance/purchases', $data)->assertCreated()
                ->assertJsonPath('data.amount', '20.25');
        }
        $this->assertDatabaseCount('finance_purchases', 2);
        $this->assertDatabaseHas('finance_purchases', ['invoice_number' => null, 'description' => '']);
        $record = Purchase::firstOrFail();
        $full = [...$data, 'invoice_number' => 'OPTIONAL-1', 'description' => 'Optional details', 'version' => 1, 'reason' => 'Add optional details'];
        $this->postJson('/api/v1/finance/purchases/'.$record->uuid, $full)->assertOk();
        $this->postJson('/api/v1/finance/purchases/'.$record->uuid, [...$data, 'version' => 2, 'reason' => 'Clear optional details'])->assertOk();
        $this->assertNull($record->fresh()->invoice_number);
        $this->assertSame('', $record->fresh()->description);
        $this->postJson('/api/v1/finance/purchases', [...$data, 'amount' => '0'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        unset($data['amount']);
        $this->postJson('/api/v1/finance/purchases', $data)->assertUnprocessable()->assertJsonValidationErrors('amount');
    }

    #[DataProvider('emptyPurchaseSuppliers')]
    public function test_direct_purchase_without_supplier_is_saved_and_included_in_reports(?string $supplier): void
    {
        $this->actor();
        $branch = Branch::factory()->create();
        $data = [...$this->outflow($branch, 'expenses'), 'invoice_number' => 'DIRECT-1'];
        unset($data['category_uuid']);
        if ($supplier !== 'omit') {
            $data['supplier_uuid'] = $supplier;
        }
        $uuid = $this->postJson('/api/v1/finance/purchases', $data)->assertCreated()
            ->assertJsonPath('data.supplier', null)->assertJsonPath('data.amount', '20.25')->json('data.uuid');
        $this->assertDatabaseHas('finance_purchases', ['uuid' => $uuid, 'supplier_id' => null, 'amount' => '20.25']);
        $this->assertDatabaseCount('finance_suppliers', 0);
        $this->assertDatabaseCount('finance_expenses', 0);
        $this->assertDatabaseCount('finance_audit_logs', 1);
        $this->getJson('/api/v1/finance/reports')->assertOk()->assertJsonPath('data.totals.purchases', '20.25');
    }

    public function test_purchase_can_assign_and_remove_supplier_with_audited_edits(): void
    {
        $this->actor();
        $branch = Branch::factory()->create();
        $data = $this->outflow($branch, 'purchases');
        $uuid = $this->postJson('/api/v1/finance/purchases', $data)->assertCreated()->json('data.uuid');
        $this->postJson('/api/v1/finance/purchases/'.$uuid, [...$data, 'supplier_uuid' => '', 'version' => 1, 'reason' => 'تصحيح إلى شراء مباشر'])
            ->assertOk()->assertJsonPath('data.supplier', null)->assertJsonPath('data.version', 2);
        $record = Purchase::where('uuid', $uuid)->firstOrFail();
        $this->assertNull($record->supplier_id);
        $this->postJson('/api/v1/finance/purchases/'.$uuid, [...$data, 'version' => 2, 'reason' => 'ربط المورد المعتمد'])
            ->assertOk()->assertJsonPath('data.supplier.uuid', $data['supplier_uuid'])->assertJsonPath('data.amount', '20.25');
        $this->assertNotNull($record->fresh()->supplier_id);
        $this->assertDatabaseCount('finance_purchases', 1);
        $this->assertDatabaseCount('finance_audit_logs', 3);
    }

    public function test_invalid_or_inactive_supplier_is_not_treated_as_direct_purchase(): void
    {
        $this->actor();
        $branch = Branch::factory()->create();
        $data = $this->outflow($branch, 'purchases');
        $this->postJson('/api/v1/finance/purchases', [...$data, 'supplier_uuid' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('supplier_uuid');
        Supplier::where('uuid', $data['supplier_uuid'])->update(['is_active' => false]);
        $this->postJson('/api/v1/finance/purchases', $data)->assertUnprocessable()->assertJsonValidationErrors('supplier_uuid');
        $this->assertDatabaseCount('finance_purchases', 0);
        $this->assertDatabaseCount('finance_audit_logs', 0);
    }

    public function test_attachment_is_private_authorized_and_not_executable(): void
    {
        Storage::fake('local');
        $this->actor();
        $branch = Branch::factory()->create();
        $data = $this->outflow($branch, 'expenses');
        $uuid = $this->post('/api/v1/finance/expenses', [...$data, 'attachment' => UploadedFile::fake()->image('receipt.jpg')], ['Accept' => 'application/json'])->assertCreated()->json('data.uuid');
        $record = Expense::firstOrFail();
        Storage::disk('local')->assertExists($record->attachment_path);
        $this->get('/api/v1/finance/expenses/'.$uuid.'/attachment')->assertOk()->assertDownload();
        $this->post('/api/v1/finance/expenses', [...$data, 'attachment' => UploadedFile::fake()->create('shell.php', 1, 'application/x-php')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->authenticateFinance(User::factory()->create()->assignRole('finance_entry'));
        $this->getJson('/api/v1/finance/expenses/'.$uuid.'/attachment')->assertNotFound();
    }

    public function test_exports_are_real_pdf_and_xlsx_and_names_are_escaped(): void
    {
        $this->actor();
        $source = RevenueSource::firstOrFail();
        $source->update(['name' => '=HYPERLINK("bad") <script>alert(1)</script>']);
        $uuid = $this->postJson('/api/v1/finance/revenues', $this->revenue(Branch::factory()->create()))->json('data.uuid');
        $this->postJson("/api/v1/finance/revenues/$uuid/approve", ['version' => 1]);
        $pdf = $this->get('/api/v1/finance/reports/export/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $pdf->assertHeader('Content-Disposition', 'attachment; filename="shishli-finance.pdf"');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->assertSame(1, AuditLog::where('action', 'reports.export')->count());
        $this->assertSame('pdf', AuditLog::where('action', 'reports.export')->firstOrFail()->new_values['format']);
        $xlsx = $this->get('/api/v1/finance/reports/export/xlsx')->assertOk();
        $xlsx->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertHeader('Content-Disposition', 'attachment; filename="shishli-finance.xlsx"');
        $this->assertStringStartsWith('PK', $xlsx->getContent());
        $path = tempnam(sys_get_temp_dir(), 'finance-test-');
        try {
            file_put_contents($path, $xlsx->getContent());
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($path));
            $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
            $this->assertStringContainsString('t="inlineStr"', $sheet);
            $this->assertStringNotContainsString('<script>', $sheet);
            $this->assertStringNotContainsString('<f>', $sheet);
            $this->assertStringContainsString('<v>100.30</v>', $sheet);
            $zip->close();
        } finally {
            unlink($path);
        }
        $this->assertSame(2, AuditLog::where('action', 'reports.export')->count());
    }

    public function test_pdf_report_embeds_restaurant_logo_and_preserves_exact_amounts_and_empty_states(): void
    {
        $this->actor();
        $report = $this->getJson('/api/v1/finance/reports')->assertOk()->json('data');
        $report['totals'] = ['revenues' => '99999999999999.99', 'expenses' => '100.10', 'purchases' => '20.25', 'net_flow' => '-0.75'];
        $report['scope']['branch'] = '<script>unsafe branch</script>';
        $exporter = app(ReportExport::class);

        $html = $exporter->html($report);

        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString('شعار مطعم شيشلي', $html);
        $this->assertStringContainsString('99,999,999,999,999.99', $html);
        $this->assertStringContainsString('-0.75', $html);
        $this->assertStringContainsString('لا توجد حركة مالية مسجلة', $html);
        $this->assertStringContainsString('لا توجد إيرادات معتمدة', $html);
        $this->assertStringContainsString('صافي التدفق المسجل ليس صافي ربح محاسبيًا', $html);
        $this->assertStringNotContainsString('<script>unsafe branch</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;unsafe branch&lt;/script&gt;', $html);
        $pdf = $exporter->pdf($report);
        $this->assertStringContainsString('/Subtype /Image', $pdf);
        $this->assertStringContainsString('Tajawal-Regular', $pdf);
        $this->assertStringContainsString('Tajawal-Bold', $pdf);
        $this->assertStringContainsString('/FontFile2', $pdf);
        $this->assertStringNotContainsString('DejaVuSans', $pdf);

        $report['periods'] = array_fill(0, 80, ['period' => '2026-11-03', ...$report['totals']]);
        $pdf = $exporter->pdf($report);
        $this->assertGreaterThan(1, preg_match_all('/\/Type\s*\/Page\b/', $pdf));
    }

    public function test_finance_user_creation_does_not_grant_restaurant_roles_or_allow_role_injection(): void
    {
        $this->actor();
        $branch = Branch::factory()->create();
        $payload = ['name' => 'محاسب', 'email' => 'finance@example.test', 'password' => 'StrongFinance123', 'role' => 'finance_accountant', 'is_active' => true, 'branch_uuids' => [$branch->uuid], 'permissions' => ['orders.view']];
        $this->postJson('/api/v1/finance/users', $payload)->assertCreated();
        $user = User::where('email', 'finance@example.test')->firstOrFail();
        $this->assertFalse($user->can('orders.view'));
        $this->assertTrue($user->hasRole('finance_accountant'));
        $this->assertDatabaseHas('finance_branch_user', ['user_id' => $user->id, 'branch_id' => $branch->id]);
        $this->postJson('/api/v1/finance/users', [...$payload, 'email' => 'evil@example.test', 'role' => 'super_admin'])->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['email' => 'evil@example.test']);
    }

    public function test_disabled_users_cannot_access_finance(): void
    {
        $user = $this->actor();
        $user->is_active = false;
        $user->save();
        $this->getJson('/api/v1/finance/me')->assertUnauthorized();
    }

    public function test_finance_seed_is_idempotent_and_does_not_reset_customized_roles(): void
    {
        $this->actor();
        $role = Role::findByName('finance_entry', 'web');
        $role->revokePermissionTo('finance.revenues.edit');
        $this->seed(FinanceSeeder::class);
        $this->assertFalse($role->fresh()->hasPermissionTo('finance.revenues.edit'));
        $this->assertDatabaseCount('finance_revenue_sources', 6);
    }

    public function test_audit_rows_cannot_be_modified(): void
    {
        $this->actor();
        $this->postJson('/api/v1/finance/revenues', $this->revenue(Branch::factory()->create()))->assertCreated();
        $this->expectException(\LogicException::class);
        AuditLog::firstOrFail()->update(['reason' => 'tamper']);
    }

    public function test_outflow_edits_require_a_reason_and_current_version(): void
    {
        $this->actor();
        $data = $this->outflow(Branch::factory()->create(), 'expenses');
        $uuid = $this->postJson('/api/v1/finance/expenses', $data)->assertCreated()->json('data.uuid');
        $this->postJson('/api/v1/finance/expenses/'.$uuid, [...$data, 'version' => 1])->assertUnprocessable()->assertJsonStructure(['errors' => ['reason']]);
        $update = [...$data, 'version' => 1, 'reason' => 'تصحيح قيمة المستند', 'amount' => '30.50'];
        $this->postJson('/api/v1/finance/expenses/'.$uuid, $update)->assertOk()->assertJsonPath('data.amount', '30.50')->assertJsonPath('data.version', 2);
        $this->postJson('/api/v1/finance/expenses/'.$uuid, $update)->assertConflict();
        $audit = AuditLog::where('action', 'expenses.update')->firstOrFail();
        $this->assertSame('20.25', $audit->old_values['amount']);
        $this->assertSame('30.50', $audit->new_values['amount']);
    }

    public function test_accountant_cannot_correct_approved_revenues_or_manage_users(): void
    {
        $this->actor();
        $branch = Branch::factory()->create();
        $data = $this->revenue($branch);
        $uuid = $this->postJson('/api/v1/finance/revenues', $data)->json('data.uuid');
        $this->postJson('/api/v1/finance/revenues/'.$uuid.'/approve', ['version' => 1])->assertOk();
        $actor = User::factory()->create()->assignRole('finance_accountant');
        DB::table('finance_branch_user')->insert(['user_id' => $actor->id, 'branch_id' => $branch->id]);
        $this->authenticateFinance($actor);
        $this->postJson('/api/v1/finance/revenues/'.$uuid.'/correct', [...$data, 'version' => 2, 'reason' => 'تصحيح غير مصرح به'])->assertForbidden();
        $this->getJson('/api/v1/finance/users')->assertForbidden();
        $this->getJson('/api/v1/finance/audit')->assertForbidden();
        $this->assertSame('100.30', RevenueDay::firstOrFail()->total);
        $this->assertDatabaseCount('finance_audit_logs', 2);
    }

    public function test_report_and_expense_lists_cannot_expose_another_branch(): void
    {
        $this->actor();
        $branch = Branch::factory()->create();
        $this->postJson('/api/v1/finance/expenses', $this->outflow($branch, 'expenses'))->assertCreated();
        $accountant = User::factory()->create()->assignRole('finance_accountant');
        $this->authenticateFinance($accountant);
        $this->getJson('/api/v1/finance/expenses')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/finance/reports')->assertOk()->assertJsonPath('data.totals.expenses', '0.00');
        $this->getJson('/api/v1/finance/reports?branch_uuid='.$branch->uuid)->assertNotFound();
        $this->getJson('/api/v1/finance/reports/export/xlsx?branch_uuid='.$branch->uuid)->assertNotFound();
    }

    public function test_negative_expenses_and_zero_purchases_do_not_write(): void
    {
        $this->actor();
        $branch = Branch::factory()->create();
        $this->postJson('/api/v1/finance/expenses', [...$this->outflow($branch, 'expenses'), 'amount' => '-1'])->assertUnprocessable();
        $this->postJson('/api/v1/finance/purchases', [...$this->outflow($branch, 'purchases'), 'amount' => '0.00'])->assertUnprocessable();
        $this->assertDatabaseCount('finance_expenses', 0);
        $this->assertDatabaseCount('finance_purchases', 0);
    }

    public function test_daily_identity_constraint_is_enforced_by_database(): void
    {
        $this->actor();
        $this->postJson('/api/v1/finance/revenues', $this->revenue(Branch::factory()->create()))->assertCreated();
        $duplicate = RevenueDay::firstOrFail()->replicate(['uuid']);
        $this->expectException(UniqueConstraintViolationException::class);
        $duplicate->save();
    }

    public function test_financial_dates_follow_riyadh_day_at_utc_month_boundary(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-31T22:00:00Z'));
        $this->actor();
        $data = [...$this->revenue(Branch::factory()->create()), 'date' => '2026-11-01'];
        $uuid = $this->postJson('/api/v1/finance/revenues', $data)->assertCreated()->json('data.uuid');
        $this->postJson('/api/v1/finance/revenues/'.$uuid.'/approve', ['version' => 1])->assertOk();
        $this->getJson('/api/v1/finance/reports')->assertOk()->assertJsonPath('data.filters.from', '2026-11-01')->assertJsonPath('data.today.revenues', '100.30')->assertJsonPath('data.month.revenues', '100.30');
        $this->getJson('/api/v1/finance/reports')->assertOk()->assertJsonPath('data.yesterday.date', '2026-10-31')->assertJsonPath('data.yesterday.revenues', '0.00');
        $yesterday = [...$data, 'date' => '2026-10-31'];
        $uuid = $this->postJson('/api/v1/finance/revenues', $yesterday)->assertCreated()->json('data.uuid');
        $this->postJson('/api/v1/finance/revenues/'.$uuid.'/approve', ['version' => 1])->assertOk();
        $this->getJson('/api/v1/finance/reports?from=2026-11-01&to=2026-11-01')->assertOk()
            ->assertJsonPath('data.yesterday.date', '2026-10-31')->assertJsonPath('data.yesterday.revenues', '100.30')
            ->assertJsonPath('data.month.revenues', '100.30');
    }

    public function test_revenue_dates_reject_future_and_allow_today_and_past_in_finance_timezone(): void
    {
        config(['finance.timezone' => 'Asia/Riyadh']);
        $this->travelTo(CarbonImmutable::parse('2026-11-04T20:59:00Z'));
        $this->actor();
        $data = $this->revenue(Branch::factory()->create());

        $this->postJson('/api/v1/finance/revenues', [...$data, 'date' => '2026-11-05'])
            ->assertUnprocessable()->assertJsonValidationErrors('date')
            ->assertJsonPath('errors.date.0', 'التاريخ لا يمكن أن يكون في المستقبل.');
        $this->assertDatabaseCount('finance_revenue_days', 0);
        $this->assertDatabaseCount('finance_audit_logs', 0);

        $this->postJson('/api/v1/finance/revenues', [...$data, 'date' => '2026-11-04'])->assertCreated();
        $this->postJson('/api/v1/finance/revenues', [...$data, 'date' => '2026-11-03'])->assertCreated();
        $this->travelTo(CarbonImmutable::parse('2026-11-04T21:00:00Z'));
        $this->postJson('/api/v1/finance/revenues', [...$data, 'date' => '2026-11-05'])->assertCreated();
        $this->assertDatabaseCount('finance_revenue_days', 3);
        $this->assertDatabaseCount('finance_audit_logs', 3);
    }

    public function test_date_filters_reject_reversed_periods_and_accept_end_date_only(): void
    {
        $this->actor();
        $this->getJson('/api/v1/finance/revenues?to='.now()->toDateString())->assertOk();
        $this->getJson('/api/v1/finance/reports?from=2026-11-02&to=2026-11-01')->assertUnprocessable();
        $this->getJson('/api/v1/finance/reports?from=2000-01-01&to=2026-11-01')->assertUnprocessable();
    }

    public function test_user_cannot_demote_or_disable_themself_and_sessions_are_revoked_on_role_change(): void
    {
        $actor = $this->actor();
        $branch = Branch::factory()->create();
        $data = ['name' => $actor->name, 'email' => $actor->email, 'role' => 'finance_entry', 'is_active' => true, 'branch_uuids' => [$branch->uuid]];
        $this->putJson('/api/v1/finance/users/'.$actor->uuid, $data)->assertUnprocessable();
        $other = User::factory()->create()->assignRole('finance_entry');
        $other->createToken('old-session');
        $this->putJson('/api/v1/finance/users/'.$other->uuid, [...$data, 'name' => $other->name, 'email' => $other->email, 'role' => 'finance_accountant'])->assertOk();
        $this->assertSame(0, $other->tokens()->count());
        $this->assertTrue($other->fresh()->hasRole('finance_accountant'));
    }

    public function test_finance_bootstrap_command_explicitly_grants_role_and_audits(): void
    {
        $this->actor();
        $target = User::factory()->create();
        $target->createToken('old-session');
        $this->artisan('finance:grant-manager', ['email' => $target->email])->assertSuccessful();
        $this->assertTrue($target->fresh()->hasRole('finance_manager'));
        $this->assertFalse($target->fresh()->can('orders.view'));
        $this->assertSame(0, $target->tokens()->count());
        $this->assertDatabaseHas('finance_audit_logs', ['action' => 'users.bootstrap_manager', 'subject_uuid' => $target->uuid]);
    }

    public function test_finance_user_manager_cannot_change_credentials_of_a_shared_restaurant_account(): void
    {
        $this->actor();
        $this->seed(RolePermissionSeeder::class);
        $target = User::factory()->create()->assignRole('finance_accountant');
        $target->givePermissionTo('orders.view');
        $password = $target->password;
        $branch = Branch::factory()->create();
        $this->putJson('/api/v1/finance/users/'.$target->uuid, [
            'name' => 'Changed', 'email' => $target->email, 'password' => 'ReplacementPassword123',
            'role' => 'finance_entry', 'is_active' => true, 'branch_uuids' => [$branch->uuid],
        ])->assertForbidden();
        $this->assertSame($password, $target->fresh()->password);
        $this->assertTrue($target->fresh()->hasRole('finance_accountant'));
        $this->assertDatabaseCount('finance_audit_logs', 0);
    }
}
