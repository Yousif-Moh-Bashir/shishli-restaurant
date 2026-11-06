<?php

namespace App\Services\Finance;

use App\Models\Branch;
use App\Models\Finance\Expense;
use App\Models\Finance\Purchase;
use App\Models\Finance\RevenueDay;
use App\Models\Finance\RevenueSource;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class ReportService
{
    public function __construct(private FinanceQuery $query) {}

    private function aggregate(Request $request, array $filters, bool $details): array
    {
        $periods = [];
        $totals = ['revenues' => 0, 'expenses' => 0, 'purchases' => 0];
        $sources = [];
        $group = $filters['group_by'] ?? 'day';
        $length = match ($group) {
            'year' => 4, 'month' => 7, default => 10
        };
        $days = $this->query->apply(RevenueDay::where('status', 'approved'), $request, $filters)->with('lines.source')->orderBy('date');
        foreach ($days->lazy(250) as $day) {
            $key = substr($day->date->toDateString(), 0, $length);
            foreach ($day->lines as $line) {
                if (! empty($filters['source_uuid']) && $filters['source_uuid'] !== $line->source->uuid) {
                    continue;
                }
                $cents = Money::cents($line->amount);
                $totals['revenues'] += $cents;
                $periods[$key]['revenues'] = ($periods[$key]['revenues'] ?? 0) + $cents;
                $source = $line->source->uuid;
                $sources[$source] ??= ['name' => $line->source->name, 'cents' => 0];
                $sources[$source]['cents'] += $cents;
            }
        }
        foreach (['expenses' => Expense::class, 'purchases' => Purchase::class] as $kind => $class) {
            foreach ($this->query->apply($class::query(), $request, $filters)->orderBy('date')->lazy(500) as $record) {
                $cents = Money::cents($record->amount);
                $totals[$kind] += $cents;
                $key = substr($record->date->toDateString(), 0, $length);
                $periods[$key][$kind] = ($periods[$key][$kind] ?? 0) + $cents;
            }
        }
        ksort($periods);
        $format = function (array $values): array {
            $result = [];
            foreach (['revenues', 'expenses', 'purchases'] as $kind) {
                $result[$kind] = Money::decimal($values[$kind] ?? 0);
            }
            $result['net_flow'] = Money::decimal(($values['revenues'] ?? 0) - ($values['expenses'] ?? 0) - ($values['purchases'] ?? 0));

            return $result;
        };

        return [
            'totals' => $format($totals),
            'periods' => $details ? collect($periods)->map(fn (array $values, string $period): array => ['period' => $period, ...$format($values)])->values()->all() : [],
            'sources' => $details ? collect($sources)->map(fn (array $value, string $uuid): array => ['uuid' => $uuid, 'name' => $value['name'], 'amount' => Money::decimal($value['cents'])])->values()->all() : [],
        ];
    }

    public function build(Request $request): array
    {
        $filters = $this->query->filters($request);
        $now = CarbonImmutable::now(config('finance.timezone'));
        $from = CarbonImmutable::parse($filters['from'] ?? $now->startOfMonth()->toDateString())->startOfDay();
        $to = CarbonImmutable::parse($filters['to'] ?? $now->toDateString())->startOfDay();
        abort_if($to->lessThan($from) || $from->diffInDays($to) > 3660, 422, 'الفترة غير صالحة أو تتجاوز عشر سنوات.');
        $filters['from'] = $from->toDateString();
        $filters['to'] = $to->toDateString();
        $result = $this->aggregate($request, $filters, true);
        $days = (int) $from->diffInDays($to) + 1;
        $previous = [...$filters, 'from' => $from->subDays($days)->toDateString(), 'to' => $from->subDay()->toDateString()];
        $today = $now->toDateString();
        $yesterday = $now->subDay()->toDateString();

        return [
            'scope' => [
                'branch' => empty($filters['branch_uuid']) ? 'كل الفروع المسموحة' : Branch::where('uuid', $filters['branch_uuid'])->value('name'),
                'source' => empty($filters['source_uuid']) ? 'كل مصادر الإيراد' : RevenueSource::where('uuid', $filters['source_uuid'])->value('name'),
                'timezone' => config('finance.timezone'),
                'currency' => config('finance.currency'),
            ],
            'filters' => $filters, ...$result,
            'previous' => ['from' => $previous['from'], 'to' => $previous['to'], ...$this->aggregate($request, $previous, false)['totals']],
            'today' => $this->aggregate($request, [...$filters, 'from' => $today, 'to' => $today], false)['totals'],
            'yesterday' => ['date' => $yesterday, ...$this->aggregate($request, [...$filters, 'from' => $yesterday, 'to' => $yesterday], false)['totals']],
            'month' => $this->aggregate($request, [...$filters, 'from' => $now->startOfMonth()->toDateString(), 'to' => $today], false)['totals'],
            'basis' => 'الإيرادات المعتمدة فقط. المصروفات والمشتريات مدفوعات مسجلة يدويًا وتُطرح كل فئة مرة واحدة. فلتر المصدر يخص الإيرادات فقط. صافي التدفق المسجل ليس صافي ربح محاسبيًا.',
        ];
    }
}
