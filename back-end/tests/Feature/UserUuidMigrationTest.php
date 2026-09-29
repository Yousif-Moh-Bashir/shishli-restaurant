<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserUuidMigrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_backfills_existing_users_and_rolls_back_without_losing_them(): void
    {
        DB::table('users')->count();
        $migration = require database_path('migrations/2026_09_23_214937_add_uuid_to_users_table.php');
        $migration->down();
        $firstId = DB::table('users')->insertGetId(User::factory()->make()->getAttributes());
        $secondId = DB::table('users')->insertGetId(User::factory()->make()->getAttributes());
        $originalUsers = DB::table('users')->orderBy('id')->get()->map(fn (object $user): array => (array) $user)->all();

        $migration->up();

        $first = DB::table('users')->find($firstId);
        $second = DB::table('users')->find($secondId);
        $this->assertTrue(Str::isUuid($first->uuid));
        $this->assertTrue(Str::isUuid($second->uuid));
        $this->assertNotSame($first->uuid, $second->uuid);

        $migration->down();

        $this->assertFalse(Schema::hasColumn('users', 'uuid'));
        $this->assertSame($originalUsers, DB::table('users')->orderBy('id')->get()->map(fn (object $user): array => (array) $user)->all());

        $migration->up();
    }
}
