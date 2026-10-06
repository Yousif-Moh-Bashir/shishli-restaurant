<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['guest_access_token_hash', 'guest_access_token_expires_at', 'estimated_ready_at', 'estimated_delivery_from', 'estimated_delivery_to'] as $column) {
            if (! Schema::hasColumn('orders', $column)) {
                Schema::table('orders', function (Blueprint $table) use ($column): void {
                    $column === 'guest_access_token_hash' ? $table->char($column, 64)->nullable() : $table->dateTime($column)->nullable();
                });
            }
        }
        if (Schema::hasColumn('orders', 'access_token')) {
            $days = (int) (DB::table('restaurant_settings')->where('key', 'orders.guest_tracking_token_days')->value('value') ?? 30);
            DB::table('orders')->whereNotNull('access_token')->select(['id', 'user_id', 'access_token', 'placed_at', 'created_at'])
                ->chunkById(200, function ($orders) use ($days): void {
                    foreach ($orders as $order) {
                        DB::table('orders')->where('id', $order->id)->update([
                            'guest_access_token_hash' => $order->user_id === null ? hash('sha256', $order->access_token) : null,
                            'guest_access_token_expires_at' => $order->user_id === null ? CarbonImmutable::parse($order->placed_at ?? $order->created_at)->addDays($days) : null,
                        ]);
                    }
                });
            if (Schema::hasIndex('orders', 'orders_access_token_unique')) {
                Schema::table('orders', fn (Blueprint $table) => $table->dropUnique('orders_access_token_unique'));
            }
            Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('access_token'));
        }
    }

    public function down(): void
    {
        // Hashes cannot recover old raw credentials. Rollback restores an empty legacy column only.
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('access_token', 64)->nullable()->unique();
            $table->dropColumn(['guest_access_token_hash', 'guest_access_token_expires_at', 'estimated_ready_at', 'estimated_delivery_from', 'estimated_delivery_to']);
        });
    }
};
