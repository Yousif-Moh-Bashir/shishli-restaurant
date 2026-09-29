<?php

namespace Tests\Feature\Models;

use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_creates_distinct_uuids_while_preserving_incrementing_internal_ids(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->assertIsInt($first->id);
        $this->assertGreaterThan($first->id, $second->id);
        $this->assertTrue(Str::isUuid($first->uuid));
        $this->assertTrue(Str::isUuid($second->uuid));
        $this->assertNotSame($first->uuid, $second->uuid);
        $this->assertSame($first->uuid, User::findOrFail($first->id)->uuid);
    }

    public function test_preserves_uuid_after_updating_a_user(): void
    {
        $user = User::factory()->create();
        $uuid = $user->uuid;

        $user->update(['name' => 'Updated name']);

        $this->assertSame($uuid, $user->fresh()->uuid);
    }

    public function test_rejects_duplicate_uuids(): void
    {
        $user = User::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);

        User::factory()->create(['uuid' => $user->uuid]);
    }

    public function test_authenticated_user_response_exposes_uuid_without_internal_id(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.uuid', $user->uuid)
            ->assertJsonMissingPath('data.id')
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');
    }

    public function test_route_binding_resolves_uuid_and_returns_404_for_internal_id(): void
    {
        Route::middleware('api')->get('/api/test-users/{user}', fn (User $user): UserResource => new UserResource($user));
        $user = User::factory()->create();

        $this->getJson('/api/test-users/'.$user->uuid)
            ->assertOk()
            ->assertJsonPath('data.uuid', $user->uuid);
        $this->getJson('/api/test-users/'.$user->id)->assertNotFound();
        $this->getJson('/api/test-users/00000000-0000-4000-8000-000000000001')->assertNotFound();
    }
}
