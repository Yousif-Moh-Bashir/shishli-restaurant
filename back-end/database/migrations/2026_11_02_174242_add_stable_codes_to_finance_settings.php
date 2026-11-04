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
        $defaults = [
            'finance_revenue_sources' => ['network' => 'شبكة', 'cash' => 'كاش', 'transfer' => 'تحويل', 'keeta' => 'كيتا', 'jahez' => 'جاهز', 'hungerstation' => 'هنقرستيشن'],
            'finance_expense_categories' => ['rent' => 'الإيجار', 'electricity' => 'الكهرباء', 'salaries' => 'الرواتب', 'maintenance' => 'الصيانة', 'operating' => 'المصاريف التشغيلية'],
        ];
        foreach ($defaults as $table => $items) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->string('code', 64)->nullable()->unique();
            });
            foreach ($items as $code => $name) {
                $candidates = DB::table('finance_audit_logs')->where('subject_type', $table)
                    ->orderBy('id')->get(['subject_uuid', 'old_values'])->filter(function (object $log) use ($name): bool {
                        return (json_decode($log->old_values, true)['name'] ?? null) === $name;
                    })->pluck('subject_uuid')->unique();
                $audited = DB::table($table)->whereIn('uuid', $candidates)->whereNull('code')->get();
                if ($audited->count() > 1) {
                    throw new RuntimeException('Ambiguous legacy finance code mapping; review UUIDs before retrying.');
                }
                $row = $audited->first() ?? DB::table($table)->where('name', $name)->whereNull('code')->first();
                if ($row) {
                    DB::table($table)->where('id', $row->id)->update(['code' => $code]);
                }
            }
            DB::table($table)->whereNull('code')->eachById(function (object $row) use ($table): void {
                DB::table($table)->where('id', $row->id)->update(['code' => 'custom_'.str_replace('-', '', $row->uuid)]);
            });
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->string('code', 64)->nullable(false)->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['finance_revenue_sources', 'finance_expense_categories'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropUnique($table.'_code_unique');
                $blueprint->dropColumn('code');
            });
        }
    }
};
