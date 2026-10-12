<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\BranchClosure;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class BranchScheduleService
{
    public function __construct(private RestaurantSettingsService $settings) {}

    public function isOpenNow(Branch $branch): bool
    {
        return $this->isOpenAt($branch, CarbonImmutable::now());
    }

    public function isOpenAt(Branch $branch, CarbonInterface $at, ?string $timezone = null): bool
    {
        return $this->currentPeriod($branch, $at, $timezone) !== null && $this->closureAt($branch, $at) === null;
    }

    public function closureAt(Branch $branch, CarbonInterface $at): ?BranchClosure
    {
        $branch->loadMissing('closures');

        return $branch->closures->first(fn (BranchClosure $closure): bool => $closure->is_active && $at->greaterThanOrEqualTo($closure->starts_at) && $at->lessThan($closure->ends_at));
    }

    /** Half-open intervals: opening instant is included and closing instant is excluded. */
    public function currentPeriod(Branch $branch, ?CarbonInterface $at = null, ?string $timezone = null): ?array
    {
        $at = CarbonImmutable::instance($at ?? CarbonImmutable::now());
        foreach ($this->periods($branch, $at, 0, $timezone) as $period) {
            if ($at->greaterThanOrEqualTo($period['opens_at']) && $at->lessThan($period['closes_at'])) {
                return $period;
            }
        }

        return null;
    }

    /** First actually open instant at/after at, searching at most fourteen local calendar days. */
    public function nextOpeningTime(Branch $branch, ?CarbonInterface $at = null, ?string $timezone = null): ?CarbonImmutable
    {
        $at = CarbonImmutable::instance($at ?? CarbonImmutable::now());
        $branch->loadMissing('closures');
        $limit = $at->setTimezone($timezone ?? $this->settings->getString('restaurant.timezone'))->addDays(14);
        foreach ($this->periods($branch, $at, 14, $timezone) as $period) {
            $candidate = $period['opens_at']->max($at);
            if ($candidate->greaterThanOrEqualTo($period['closes_at'])) {
                continue;
            }
            do {
                $closure = $this->closureAt($branch, $candidate);
                if ($closure !== null) {
                    $candidate = $closure->ends_at;
                }
            } while ($closure !== null && $candidate->lessThan($period['closes_at']));
            if ($candidate->lessThan($period['closes_at']) && $candidate->lessThanOrEqualTo($limit)) {
                return $candidate->utc();
            }
        }

        return null;
    }

    public function nextOpeningAt(Branch $branch, ?CarbonInterface $at = null): ?CarbonImmutable
    {
        return $this->nextOpeningTime($branch, $at);
    }

    /** Partition local work dates at the preceding day's last overnight closing, not at UTC midnight. */
    public function businessDayWindow(?Branch $branch, ?string $date = null, ?string $timezone = null): array
    {
        $timezone ??= $this->settings->getString('restaurant.timezone');
        $now = CarbonImmutable::now($timezone);
        $day = ($date === null ? $now : CarbonImmutable::parse($date, $timezone))->startOfDay();
        $branch?->loadMissing('businessHours');
        $rollover = function (CarbonImmutable $day) use ($branch): string {
            $times = $branch?->businessHours->filter(fn ($hour): bool => $hour->day_of_week === $day->isoWeekday()
                && $hour->closes_at < $hour->opens_at)->pluck('closes_at')->sort();

            return substr($times?->last() ?? '00:00', 0, 5);
        };
        $start = $day->setTimeFromTimeString($rollover($day->subDay()));
        if ($date === null && $now->lessThan($start)) {
            $day = $day->subDay();
            $start = $day->setTimeFromTimeString($rollover($day->subDay()));
        }
        $end = $day->addDay()->setTimeFromTimeString($rollover($day));

        return ['date' => $day->toDateString(), 'timezone' => $timezone, 'start' => $start->utc(), 'end' => $end->utc()];
    }

    private function periods(Branch $branch, CarbonImmutable $at, int $days, ?string $timezone): array
    {
        $branch->loadMissing('businessHours');
        $date = $at->setTimezone($timezone ?? $this->settings->getString('restaurant.timezone'))->startOfDay();
        $periods = [];
        for ($offset = -1; $offset <= $days; $offset++) {
            $day = $date->addDays($offset);
            foreach ($branch->businessHours as $hour) {
                if ($hour->day_of_week !== $day->isoWeekday()) {
                    continue;
                }
                $opens = $day->setTimeFromTimeString($hour->opens_at);
                $closes = $day->setTimeFromTimeString($hour->closes_at);
                if ($hour->closes_at < $hour->opens_at) {
                    $closes = $day->addDay()->setTimeFromTimeString($hour->closes_at);
                }
                $periods[] = ['opens_at' => $opens->utc(), 'closes_at' => $closes->utc()];
            }
        }
        usort($periods, fn (array $a, array $b): int => $a['opens_at']->getTimestamp() <=> $b['opens_at']->getTimestamp());

        return $periods;
    }
}
