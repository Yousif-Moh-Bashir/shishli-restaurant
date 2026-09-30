<?php

namespace Tests\Feature\Api\V1;

use App\Enums\CartStatus;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CartTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_creation_returns_unique_secret_once_and_hides_database_ids(): void
    {
        $branch = Branch::factory()->create();
        $first = $this->postJson('/api/v1/cart', ['branch_uuid' => $branch->uuid, 'user_id' => 123, 'total' => '0.01'])
            ->assertCreated()->assertJsonPath('data.summary.total', '0.00')->assertJsonPath('data.can_checkout', false)
            ->assertJsonPath('data.branch.id', $branch->uuid)->assertJsonMissingPath('data.user_id')->assertJsonMissingPath('data.branch_id');
        $token = $first->json('data.token');
        $this->assertSame(64, strlen($token));
        $this->assertTrue(Str::isUuid($first->json('data.id')));
        $cart = Cart::where('uuid', $first->json('data.id'))->firstOrFail();
        $this->assertSame(hash('sha256', $token), $cart->token);
        $this->assertNull($cart->user_id);
        $this->assertNotNull($cart->expires_at);
        $this->assertArrayNotHasKey('token', $cart->toArray());
        $second = $this->postJson('/api/v1/cart', ['branch_uuid' => $branch->uuid])->assertCreated();
        $this->assertNotSame($token, $second->json('data.token'));
        $this->withHeader('X-Cart-Token', $token)->getJson('/api/v1/cart')
            ->assertOk()->assertJsonPath('data.id', $cart->uuid)->assertJsonMissingPath('data.token');
    }

    #[DataProvider('badBranches')]
    public function test_create_validates_branch(array $payload): void
    {
        $this->postJson('/api/v1/cart', $payload)->assertUnprocessable()->assertJsonValidationErrors('branch_uuid');
        $this->assertDatabaseCount('carts', 0);
    }

    public static function badBranches(): array
    {
        return [[[]], [['branch_uuid' => 'invalid']], [['branch_uuid' => '11111111-1111-4111-8111-111111111111']]];
    }

    #[DataProvider('branchStates')]
    public function test_unorderable_branches_cannot_create_carts(string $state): void
    {
        $branch = Branch::factory()->create();
        match ($state) {
            'inactive' => $branch->update(['is_active' => false]),
            'closed' => $branch->update(['accepts_orders' => false]),
            'deleted' => $branch->delete(),
        };
        $this->postJson('/api/v1/cart', ['branch_uuid' => $branch->uuid])->assertUnprocessable();
        $this->assertDatabaseCount('carts', 0);
    }

    public static function branchStates(): array
    {
        return [['inactive'], ['closed'], ['deleted']];
    }

    public function test_guest_needs_header_token_and_uuid_or_query_token_is_insufficient(): void
    {
        $branch = Branch::factory()->create();
        $response = $this->postJson('/api/v1/cart', ['branch_uuid' => $branch->uuid])->assertCreated();
        $this->getJson('/api/v1/cart')->assertUnauthorized();
        $this->getJson('/api/v1/cart?token='.$response->json('data.token'))->assertUnauthorized();
        $this->withHeader('X-Cart-UUID', $response->json('data.id'))->getJson('/api/v1/cart')->assertUnauthorized();
        $this->withHeader('X-Cart-Token', Str::random(64))->getJson('/api/v1/cart')->assertNotFound();
    }

    #[DataProvider('cartStates')]
    public function test_inactive_or_expired_cart_is_rejected_for_reads_and_mutations(string $state): void
    {
        $cart = Cart::factory()->create();
        $token = Str::random(64);
        $cart->token = hash('sha256', $token);
        match ($state) {
            'expired' => $cart->expires_at = now()->subSecond(),
            'converted' => $cart->status = CartStatus::Converted,
            'abandoned' => $cart->status = CartStatus::Abandoned,
        };
        $cart->save();
        $this->withHeader('X-Cart-Token', $token)->getJson('/api/v1/cart')->assertNotFound();
        $this->deleteJson('/api/v1/cart/items')->assertNotFound();
        $this->postJson('/api/v1/cart/refresh')->assertNotFound();
    }

    public static function cartStates(): array
    {
        return [['expired'], ['converted'], ['abandoned']];
    }

    public function test_authenticated_creation_reuses_cart_and_requires_selector_when_multiple_branches_exist(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');
        $branch = Branch::factory()->create();
        $first = $this->postJson('/api/v1/cart', ['branch_uuid' => $branch->uuid])
            ->assertCreated()->assertJsonMissingPath('data.token')->json('data.id');
        $this->postJson('/api/v1/cart', ['branch_uuid' => $branch->uuid])->assertOk()->assertJsonPath('data.id', $first);
        $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.id', $first);
        $this->assertSame($user->id, Cart::first()->user_id);
        $other = Branch::factory()->create();
        $this->postJson('/api/v1/cart', ['branch_uuid' => $other->uuid])->assertCreated();
        $this->getJson('/api/v1/cart')->assertUnprocessable()->assertJsonValidationErrors('cart');
        $this->withHeader('X-Cart-UUID', $first)->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.id', $first);
        $this->assertDatabaseCount('carts', 2);
    }

    public function test_real_sanctum_bearer_authentication_is_optional_but_invalid_bearer_is_rejected(): void
    {
        $user = User::factory()->create();
        $branch = Branch::factory()->create();
        $this->withToken($user->createToken('cart-test')->plainTextToken)
            ->postJson('/api/v1/cart', ['branch_uuid' => $branch->uuid])->assertCreated()->assertJsonMissingPath('data.token');
        $this->assertSame($user->id, Cart::first()->user_id);
        Auth::forgetGuards();
        $this->withToken('invalid')->postJson('/api/v1/cart', ['branch_uuid' => $branch->uuid])->assertUnauthorized();
    }

    public function test_user_cannot_read_another_users_cart_even_with_its_token(): void
    {
        $owner = User::factory()->create();
        $cart = Cart::factory()->authenticated($owner)->create();
        $secret = Str::random(64);
        $cart->token = hash('sha256', $secret);
        $cart->save();
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->withHeader('X-Cart-UUID', $cart->uuid)->getJson('/api/v1/cart')->assertNotFound();
        $this->withHeader('X-Cart-Token', $secret)->getJson('/api/v1/cart')->assertNotFound();
        Auth::forgetGuards();
        $this->getJson('/api/v1/cart')->assertNotFound();
    }

    public function test_expired_authenticated_cart_is_abandoned_before_replacement(): void
    {
        $user = User::factory()->create();
        $cart = Cart::factory()->authenticated($user)->create(['expires_at' => now()->subDay()]);
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/cart', ['branch_uuid' => $cart->branch->uuid])->assertCreated();
        $this->assertSame(CartStatus::Abandoned, $cart->fresh()->status);
        $this->assertDatabaseCount('carts', 2);
    }

    public function test_database_prevents_two_active_carts_for_same_user_and_branch(): void
    {
        $cart = Cart::factory()->authenticated(User::factory()->create())->create();
        $this->expectException(UniqueConstraintViolationException::class);
        Cart::factory()->authenticated($cart->user)->forBranch($cart->branch)->create();
    }

    public function test_branch_change_requires_empty_cart_and_cannot_collide_with_owned_active_cart(): void
    {
        $user = User::factory()->create();
        $cart = Cart::factory()->authenticated($user)->create();
        $target = Branch::factory()->create();
        $this->actingAs($user, 'sanctum')->withHeader('X-Cart-UUID', $cart->uuid)
            ->patchJson('/api/v1/cart', ['branch_uuid' => $target->uuid])->assertOk()->assertJsonPath('data.branch.id', $target->uuid);
        $item = CartItem::factory()->for($cart)->create();
        $third = Branch::factory()->create();
        $this->patchJson('/api/v1/cart', ['branch_uuid' => $third->uuid])->assertUnprocessable();
        $item->delete();
        Cart::factory()->authenticated($user)->forBranch($third)->create();
        $this->patchJson('/api/v1/cart', ['branch_uuid' => $third->uuid])->assertUnprocessable();
        $this->assertSame($target->id, $cart->fresh()->branch_id);
    }

    public function test_cart_creation_and_cart_access_are_rate_limited(): void
    {
        $this->freezeTime();
        $branch = Branch::factory()->create();
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->postJson('/api/v1/cart', ['branch_uuid' => $branch->uuid])->assertCreated();
        }
        $this->postJson('/api/v1/cart', ['branch_uuid' => $branch->uuid])->assertTooManyRequests()->assertHeader('Retry-After');
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/cart', ['branch_uuid' => $branch->uuid])->assertCreated();
        $this->withHeader('X-Cart-Token', Str::random(64));
        for ($attempt = 0; $attempt < 120; $attempt++) {
            $this->getJson('/api/v1/cart')->assertNotFound();
        }
        $this->getJson('/api/v1/cart')->assertTooManyRequests();
    }

    public function test_empty_cart_can_move_to_branch_with_expired_owned_cart(): void
    {
        $user = User::factory()->create();
        $cart = Cart::factory()->authenticated($user)->create();
        $expired = Cart::factory()->authenticated($user)->create(['expires_at' => now()->subDay()]);

        $this->actingAs($user, 'sanctum')->withHeader('X-Cart-UUID', $cart->uuid)
            ->patchJson('/api/v1/cart', ['branch_uuid' => $expired->branch->uuid])->assertOk()
            ->assertJsonPath('data.branch.id', $expired->branch->uuid);
        $this->assertSame(CartStatus::Abandoned, $expired->fresh()->status);
        $this->assertSame($expired->branch_id, $cart->fresh()->branch_id);
    }
}
