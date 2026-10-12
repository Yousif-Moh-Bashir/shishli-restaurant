<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $automaticPlacement = false;
        if (DB::connection()->getDriverName() === 'mysql') {
            $column = DB::selectOne("SHOW COLUMNS FROM orders WHERE Field = 'placed_at'");
            $automaticPlacement = str_contains(strtolower($column->Extra ?? ''), 'on update');
        }
        Schema::table('orders', function (Blueprint $table): void {
            $table->dateTime('placed_at')->default(null)->change();
        });
        if ($automaticPlacement) {
            // Checkout places and creates an order together. Restore only these known snapshots.
            DB::table('orders')->whereNotNull('cart_id')->whereNotNull('created_at')
                ->whereColumn('placed_at', '<>', 'created_at')
                ->update(['placed_at' => DB::raw('created_at')]);
        }
    }

    public function down(): void
    {
        // Corrected historical times are retained; never restore automatic placement updates.
        Schema::table('orders', function (Blueprint $table): void {
            $table->timestamp('placed_at')->useCurrent()->change();
        });
    }
};
