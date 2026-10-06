<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->boolean('tracks_inventory')->default(false));
        Schema::create('branch_inventories', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('on_hand', 12, 3)->default(0);
            $table->decimal('reserved', 12, 3)->default(0);
            $table->decimal('low_stock_threshold', 12, 3)->nullable();
            $table->boolean('is_tracking')->default(true);
            $table->timestamps();
            $table->unique(['branch_id', 'product_id']);
        });
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            $version = DB::selectOne('SELECT VERSION() AS version')->version;
            $number = preg_replace('/[^0-9.].*$/', '', $version);
            $supported = str_contains($version, 'MariaDB') ? version_compare($number, '10.2.1', '>=') : version_compare($number, '8.0.16', '>=');
            if ($supported) {
                DB::statement('ALTER TABLE branch_inventories ADD CONSTRAINT inventory_balances_valid CHECK (on_hand >= 0 AND reserved >= 0 AND reserved <= on_hand)');
                DB::statement('ALTER TABLE branch_inventories ADD CONSTRAINT inventory_threshold_valid CHECK (low_stock_threshold IS NULL OR low_stock_threshold >= 0)');
            }
        } elseif ($driver === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $event) {
                DB::statement('CREATE TRIGGER inventory_balance_'.strtolower($event).' BEFORE '.$event.' ON branch_inventories
                    WHEN NEW.on_hand < 0 OR NEW.reserved < 0 OR NEW.reserved > NEW.on_hand OR NEW.low_stock_threshold < 0
                    BEGIN SELECT RAISE(ABORT, "Invalid inventory balances"); END');
            }
        }
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_inventory_id')->constrained()->restrictOnDelete();
            $table->string('type', 30);
            $table->decimal('quantity', 12, 3);
            foreach (['on_hand_before', 'on_hand_after', 'reserved_before', 'reserved_after'] as $field) {
                $table->decimal($field, 12, 3);
            }
            $table->string('reference_type', 40)->nullable();
            $table->uuid('reference_uuid')->nullable()->index();
            $table->string('operation_key', 100)->nullable()->unique();
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->dateTime('created_at');
            $table->index(['branch_inventory_id', 'created_at']);
            $table->index(['type', 'created_at']);
        });
        Schema::create('stock_reservations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_inventory_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->string('status', 20);
            $table->dateTime('reserved_at');
            $table->dateTime('consumed_at')->nullable();
            $table->dateTime('released_at')->nullable();
            $table->timestamps();
            $table->unique(['order_item_id', 'branch_inventory_id']);
            $table->index(['order_id', 'status']);
            $table->index(['branch_inventory_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('branch_inventories');
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('tracks_inventory'));
    }
};
