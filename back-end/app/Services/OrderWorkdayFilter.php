<?php

namespace App\Services;

use App\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class OrderWorkdayFilter
{
    public function __construct(private BranchScheduleService $schedule, private RestaurantSettingsService $settings) {}

    public function apply(Builder $query, array $filters, string $column = 'placed_at'): Builder
    {
        $timezone = $this->settings->getString('restaurant.timezone');
        // Explicit dates used by reporting clients keep their existing calendar-date contract.
        $period = $filters['period'] ?? (isset($filters['date_from']) || isset($filters['date_to']) ? 'calendar' : 'current');
        if ($period === 'calendar') {
            if (isset($filters['date_from'])) {
                $query->where($column, '>=', CarbonImmutable::parse($filters['date_from'], $timezone)->startOfDay()->utc());
            }
            if (isset($filters['date_to'])) {
                $query->where($column, '<', CarbonImmutable::parse($filters['date_to'], $timezone)->addDay()->startOfDay()->utc());
            }

            return $query;
        }
        $branches = Branch::withTrashed()->with('businessHours');
        if (isset($filters['branch'])) {
            $branches->where('uuid', $filters['branch']);
        }
        $windows = [];
        foreach ($branches->get()->push(null) as $branch) {
            $current = $this->schedule->businessDayWindow($branch, timezone: $timezone);
            $from = $period === 'history' ? null : $current['start'];
            $until = $period === 'history' ? $current['start'] : $current['end'];
            if ($period === 'range') {
                $from = isset($filters['date_from']) ? $this->schedule->businessDayWindow($branch, $filters['date_from'], $timezone)['start'] : null;
                $until = isset($filters['date_to']) ? $this->schedule->businessDayWindow($branch, $filters['date_to'], $timezone)['end'] : null;
            }
            $windows[] = ['branch_id' => $branch?->id, 'from' => $from, 'until' => $until];
        }
        $query->where(function (Builder $query) use ($windows, $column): void {
            foreach ($windows as $window) {
                $query->orWhere(function (Builder $query) use ($window, $column): void {
                    $query->where('branch_id', $window['branch_id']);
                    if ($window['from'] !== null) {
                        $query->where($column, '>=', $window['from']);
                    }
                    if ($window['until'] !== null) {
                        $query->where($column, '<', $window['until']);
                    }
                });
            }
        });

        return $query;
    }
}
