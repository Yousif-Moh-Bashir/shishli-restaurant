<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminStaffConcurrencyTest extends TestCase
{
    public function test_concurrent_self_demotions_preserve_one_active_super_admin(): void
    {
        if (getenv('INVENTORY_MYSQL_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Enable the existing MySQL concurrency suite to verify staff locking.');
        }
        $database = 'shishli_staff_test_'.bin2hex(random_bytes(8));
        $this->assertMatchesRegularExpression('/^shishli_staff_test_[a-f0-9]{16}$/D', $database);
        $connection = config('database.connections.mysql');
        $connection['url'] = null;
        $connection['database'] = $database;
        $server = new \PDO('mysql:host='.$connection['host'].';port='.$connection['port'].';charset=utf8mb4', $connection['username'], $connection['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $original = DB::getDefaultConnection();
        $processes = [];
        try {
            config(['database.connections.staff_concurrency' => $connection]);
            DB::setDefaultConnection('staff_concurrency');
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'staff_concurrency', '--force' => true, '--no-interaction' => true]));
            $this->seed(RolePermissionSeeder::class);
            $users = User::factory()->count(2)->create()->each(fn (User $user) => $user->assignRole('super_admin'));
            foreach ($users as $user) {
                $bootstrap = var_export(base_path(), true);
                $encoded = var_export(base64_encode(json_encode($connection, JSON_THROW_ON_ERROR)), true);
                $code = 'require '.$bootstrap.'."/vendor/autoload.php"; $app=require '.$bootstrap.'."/bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();'
                 .'config(["database.connections.staff_concurrency"=>json_decode(base64_decode('.$encoded.'),true),"database.default"=>"staff_concurrency","cache.default"=>"array","queue.default"=>"sync"]); Illuminate\\Support\\Facades\\DB::setDefaultConnection("staff_concurrency");'
                 .'$user=App\\Models\\User::findOrFail('.$user->id.'); $user->load("roles.permissions"); echo "READY\\n"; fflush(STDOUT); fgets(STDIN);'
                 .'try { app(App\\Services\\Admin\\StaffService::class)->update($user,$user,["roles"=>["manager"]]); echo "DEMOTED\\n"; } catch(Illuminate\\Validation\\ValidationException $e) { echo in_array("LAST_SUPER_ADMIN",$e->errors()["roles"]??[],true)?"PRESERVED\\n":"ERROR\\n"; }';
                $pipes = [];
                $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                stream_set_timeout($pipes[1], 30);
                $processes[] = ['process' => $process, 'pipes' => $pipes];
            }
            foreach ($processes as $process) {
                $this->assertSame("READY\n", fgets($process['pipes'][1]));
            }
            foreach ($processes as $process) {
                fwrite($process['pipes'][0], "GO\n");
                fflush($process['pipes'][0]);
            }
            $results = [];
            foreach ($processes as $process) {
                $results[] = trim((string) fgets($process['pipes'][1]));
                fclose($process['pipes'][0]);
                $errors = stream_get_contents($process['pipes'][2]);
                fclose($process['pipes'][1]);
                fclose($process['pipes'][2]);
                $this->assertSame(0, proc_close($process['process']), $errors);
            }
            $processes = [];
            sort($results);
            $this->assertSame(['DEMOTED', 'PRESERVED'], $results);
            $this->assertSame(1, User::where('is_active', true)->role('super_admin', 'web')->count());
            $this->assertDatabaseCount('admin_activity_logs', 1);
        } finally {
            foreach ($processes as $process) {
                if (is_resource($process['process'])) {
                    proc_terminate($process['process']);
                } foreach ($process['pipes'] as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                } if (is_resource($process['process'])) {
                    proc_close($process['process']);
                }
            }
            DB::purge('staff_concurrency');
            DB::setDefaultConnection($original);
            $server->exec('DROP DATABASE `'.$database.'`');
        }
    }
}
