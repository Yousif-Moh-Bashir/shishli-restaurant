<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('key', 40)->unique();
            $table->string('title');
            $table->string('slug', 160)->unique();
            $table->string('status', 20)->default('draft');
            $table->string('meta_title', 100)->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->string('canonical_url', 2048)->nullable();
            $table->string('og_image')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'published_at']);
        });
        Schema::create('page_sections', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->string('key', 50);
            $table->string('type', 30);
            $table->string('title')->nullable();
            $table->string('subtitle', 1000)->nullable();
            $table->longText('content')->nullable();
            $table->json('data')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['page_id', 'key']);
            $table->index(['page_id', 'is_active', 'sort_order']);
        });
        Schema::create('banners', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('placement', 40);
            $table->string('title')->nullable();
            $table->string('subtitle', 1000)->nullable();
            $table->string('image');
            $table->string('mobile_image')->nullable();
            $table->string('button_text', 100)->nullable();
            $table->string('button_url', 2048)->nullable();
            $table->string('secondary_button_text', 100)->nullable();
            $table->string('secondary_button_url', 2048)->nullable();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['placement', 'is_active', 'sort_order']);
        });
        Schema::create('gallery_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('image');
            $table->string('alt_text')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->dateTime('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['is_active', 'published_at', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gallery_items');
        Schema::dropIfExists('banners');
        Schema::dropIfExists('page_sections');
        Schema::dropIfExists('pages');
    }
};
