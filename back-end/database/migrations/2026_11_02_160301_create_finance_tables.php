<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['finance_revenue_sources', 'finance_expense_categories'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('name', 120)->unique();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
        Schema::create('finance_branch_user', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->primary(['user_id', 'branch_id']);
        });
        Schema::create('finance_suppliers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 160);
            $table->string('phone', 40)->nullable();
            $table->string('tax_number', 40)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('finance_revenue_days', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->decimal('total', 16, 2)->default(0);
            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['branch_id', 'date']);
            $table->index(['status', 'date']);
        });
        Schema::create('finance_revenue_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('revenue_day_id')->constrained('finance_revenue_days')->cascadeOnDelete();
            $table->foreignId('source_id')->constrained('finance_revenue_sources')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->unique(['revenue_day_id', 'source_id']);
        });
        foreach (['finance_expenses', 'finance_purchases'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('branch_id')->constrained()->restrictOnDelete();
                $table->date('date');
                $table->decimal('amount', 14, 2);
                $table->text('description');
                $table->string('payment_method', 30);
                $table->string('attachment_path')->nullable();
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->unsignedInteger('version')->default(1);
                if ($name === 'finance_expenses') {
                    $table->foreignId('category_id')->constrained('finance_expense_categories')->restrictOnDelete();
                } else {
                    $table->foreignId('supplier_id')->constrained('finance_suppliers')->restrictOnDelete();
                    $table->string('invoice_number', 100);
                    $table->unique(['supplier_id', 'invoice_number']);
                }
                $table->timestamps();
                $table->index(['branch_id', 'date']);
            });
        }
        Schema::create('finance_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('action', 80);
            $table->string('subject_type', 100);
            $table->uuid('subject_uuid');
            $table->json('old_values');
            $table->json('new_values');
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['branch_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['finance_audit_logs', 'finance_purchases', 'finance_expenses', 'finance_revenue_lines', 'finance_revenue_days', 'finance_suppliers', 'finance_branch_user', 'finance_expense_categories', 'finance_revenue_sources'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
