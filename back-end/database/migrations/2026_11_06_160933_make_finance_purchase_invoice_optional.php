<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('finance_purchases', function (Blueprint $table) {
            $table->string('invoice_number', 100)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('finance_purchases')->whereNull('invoice_number')->exists()) {
            throw new RuntimeException('Cannot require invoice numbers while purchases without invoice numbers exist.');
        }
        Schema::table('finance_purchases', function (Blueprint $table) {
            $table->string('invoice_number', 100)->nullable(false)->change();
        });
    }
};
