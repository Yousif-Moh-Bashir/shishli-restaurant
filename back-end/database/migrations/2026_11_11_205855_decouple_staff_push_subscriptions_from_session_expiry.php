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
        Schema::table('staff_push_subscriptions', function (Blueprint $table): void {
            $table->dropForeign(['personal_access_token_id']);
        });
        Schema::table('staff_push_subscriptions', function (Blueprint $table): void {
            $table->unsignedBigInteger('personal_access_token_id')->nullable()->change();
            $table->foreign('personal_access_token_id')->references('id')->on('personal_access_tokens')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('staff_push_subscriptions')->whereNull('personal_access_token_id')->exists()) {
            throw new RuntimeException('Cannot restore session binding while independent device registrations exist. Use a forward fix.');
        }
        Schema::table('staff_push_subscriptions', function (Blueprint $table): void {
            $table->dropForeign(['personal_access_token_id']);
        });
        Schema::table('staff_push_subscriptions', function (Blueprint $table): void {
            $table->unsignedBigInteger('personal_access_token_id')->nullable(false)->change();
            $table->foreign('personal_access_token_id')->references('id')->on('personal_access_tokens')->cascadeOnDelete();
        });
    }
};
