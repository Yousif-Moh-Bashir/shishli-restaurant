<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->string('city', 100)->nullable();
            $table->string('district', 100)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->softDeletes();
            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->dropIndex(['is_active', 'sort_order']);
            $table->dropColumn(['city', 'district', 'sort_order', 'deleted_at']);
        });
    }
};
