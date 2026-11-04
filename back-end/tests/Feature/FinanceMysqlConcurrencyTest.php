<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Finance\AuditLog;
use App\Models\Finance\RevenueDay;
use App\Models\Finance\RevenueSource;
use App\Models\Finance\Supplier;
use App\Models\User;
use App\Services\Finance\RevenueService;
use Database\Seeders\FinanceSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FinanceMysqlConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (getenv('FINANCE_MYSQL_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Requires the isolated docker/compose.finance-test.yaml stack.');
        }
        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('finance-mysql', config('database.connections.mysql.host'));
        $this->assertSame('shishli_finance_test', config('database.connections.mysql.database'));
        $this->assertEmpty(config('database.connections.mysql.url'));
    }

    public static function operations(): array
    {
        return ['create day' => ['create', [201, 422]], 'approve day' => ['approve', [200, 409]], 'correct approved day' => ['correct', [200, 409]], 'duplicate purchase' => ['purchase', [201, 422]]];
    }

    #[DataProvider('operations')]
    public function test_two_concurrent_requests_cannot_duplicate_or_overwrite_a_financial_operation(string $operation, array $expected): void
    {
        $this->seed(FinanceSeeder::class);
        $branch = Branch::factory()->create();
        $actor = User::factory()->create()->assignRole('finance_manager');
        $token = $actor->createToken('finance', ['finance'], now()->addHours(8))->plainTextToken;
        $data = ['branch_uuid' => $branch->uuid, 'date' => now(config('finance.timezone'))->toDateString(), 'lines' => [['source_uuid' => RevenueSource::firstOrFail()->uuid, 'amount' => '0.30']]];
        $service = app(RevenueService::class);
        $day = in_array($operation, ['create', 'purchase'], true) ? null : $service->save($actor, $data);
        if ($operation === 'correct') {
            $day = $service->approve($actor, $day->uuid, 1);
        }
        $uri = '/api/v1/finance/revenues'.($day ? '/'.$day->uuid.'/'.$operation : '');
        $payload = match ($operation) {
            'approve' => ['version' => 1],
            'correct' => [...$data, 'version' => 2, 'reason' => 'تصحيح متزامن للاختبار', 'lines' => [['source_uuid' => RevenueSource::firstOrFail()->uuid, 'amount' => '0.40']]],
            default => $data,
        };
        $supplier = null;
        if ($operation === 'purchase') {
            $supplier = Supplier::create(['name' => 'مورد اختبار التزامن', 'is_active' => true]);
            $uri = '/api/v1/finance/purchases';
            $payload = ['branch_uuid' => $branch->uuid, 'date' => $data['date'], 'amount' => '0.30', 'description' => 'فاتورة متزامنة', 'payment_method' => 'cash', 'supplier_uuid' => $supplier->uuid, 'invoice_number' => 'RACE-1'];
        }
        $processes = [];
        DB::beginTransaction();
        try {
            if ($day) {
                RevenueDay::whereKey($day->id)->lockForUpdate()->firstOrFail();
            } elseif ($supplier) {
                Supplier::whereKey($supplier->id)->lockForUpdate()->firstOrFail();
            } else {
                Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            }
            for ($i = 0; $i < 2; $i++) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, base_path('tests/Support/finance-race-worker.php')], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                $processes[] = ['process' => $process, 'pipes' => $pipes];
                stream_set_timeout($pipes[1], 30);
                fwrite($pipes[0], json_encode(compact('uri', 'token', 'payload'), JSON_THROW_ON_ERROR)."\n");
                fflush($pipes[0]);
            }
            foreach ($processes as $process) {
                $this->assertSame("READY\n", fgets($process['pipes'][1]));
                fwrite($process['pipes'][0], "GO\n");
                fflush($process['pipes'][0]);
            }
            foreach ($processes as $process) {
                $this->assertSame("START\n", fgets($process['pipes'][1]));
            }
            $read = array_map(fn (array $process) => $process['pipes'][1], $processes);
            $write = $except = null;
            $this->assertSame(0, stream_select($read, $write, $except, 0, 200000), 'Requests must wait for the financial row lock.');
            DB::commit();
            $results = [];
            foreach ($processes as $process) {
                $results[] = (int) trim((string) fgets($process['pipes'][1]));
            }
            sort($results);
            $this->assertSame($expected, $results);
            if ($operation === 'purchase') {
                $this->assertDatabaseCount('finance_purchases', 1);
                $this->assertSame(1, AuditLog::where('action', 'purchases.create')->count());
            } else {
                $this->assertDatabaseCount('finance_revenue_days', 1);
                $this->assertSame($operation === 'correct' ? '0.40' : '0.30', RevenueDay::firstOrFail()->total);
                $this->assertSame(1, AuditLog::where('action', 'revenues.'.($operation === 'create' ? 'create' : $operation))->count());
            }
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                foreach ($process['pipes'] as $pipe) {
                    fclose($pipe);
                }
                proc_terminate($process['process']);
                proc_close($process['process']);
            }
        }
    }
}
