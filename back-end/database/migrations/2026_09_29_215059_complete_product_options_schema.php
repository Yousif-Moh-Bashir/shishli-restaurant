<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['option_groups', 'option_values'] as $name) {
            foreach (DB::table($name)->select(['id', 'name'])->lazyById() as $record) {
                if (mb_strlen($record->name) > 150) {
                    throw new RuntimeException("{$name} record {$record->id} exceeds the new name limit.");
                }
            }
        }

        foreach (['option_groups', 'option_values'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->uuid('uuid')->nullable()->unique();
                $table->string('slug', 180)->nullable();
                $table->string('name', 150)->change();
                $table->timestamps();
                $table->softDeletes();
            });
            foreach (DB::table($name)->select('id')->lazyById() as $record) {
                $uuid = (string) Str::uuid();
                DB::table($name)->where('id', $record->id)->update([
                    'uuid' => $uuid, 'slug' => ($name === 'option_groups' ? 'option-group-' : 'option-').$uuid,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            Schema::table($name, function (Blueprint $table): void {
                $table->uuid('uuid')->nullable(false)->change();
                $table->string('slug', 180)->nullable(false)->change();
            });
        }

        Schema::table('option_groups', function (Blueprint $table): void {
            $table->unsignedInteger('max_select')->nullable()->default(1)->change();
            $table->unique('slug');
            $table->index(['is_active', 'deleted_at', 'sort_order']);
        });
        Schema::table('option_values', function (Blueprint $table): void {
            $table->unique(['option_group_id', 'slug']);
            $table->index(['option_group_id', 'is_active', 'deleted_at', 'sort_order'], 'option_values_visibility_index');
        });
        Schema::table('product_option_groups', function (Blueprint $table): void {
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_required_override')->nullable();
            $table->unsignedInteger('min_select_override')->nullable();
            $table->unsignedInteger('max_select_override')->nullable();
            $table->timestamps();
        });

        foreach (DB::table('option_groups')->select(['id', 'sort_order'])->lazyById() as $group) {
            DB::table('product_option_groups')->where('option_group_id', $group->id)->update([
                'sort_order' => $group->sort_order, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (DB::table('option_groups')->whereNull('max_select')->exists()) {
            throw new RuntimeException('Set finite group limits before rolling back this migration.');
        }

        Schema::table('product_option_groups', function (Blueprint $table): void {
            $table->dropColumn(['sort_order', 'is_required_override', 'min_select_override', 'max_select_override', 'created_at', 'updated_at']);
        });
        Schema::table('option_values', function (Blueprint $table): void {
            $table->dropUnique('option_values_option_group_id_slug_unique');
            $table->dropIndex('option_values_visibility_index');
        });
        Schema::table('option_groups', function (Blueprint $table): void {
            $table->dropUnique('option_groups_slug_unique');
            $table->dropIndex('option_groups_is_active_deleted_at_sort_order_index');
            $table->unsignedInteger('max_select')->nullable(false)->default(1)->change();
        });
        foreach (['option_values', 'option_groups'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->dropUnique($name.'_uuid_unique');
                $table->dropColumn(['uuid', 'slug', 'created_at', 'updated_at', 'deleted_at']);
                $table->string('name', 255)->change();
            });
        }
    }
};
