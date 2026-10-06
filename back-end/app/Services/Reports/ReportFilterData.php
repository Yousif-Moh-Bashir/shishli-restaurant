<?php

namespace App\Services\Reports;

use App\Services\RestaurantSettingsService;
use Carbon\CarbonImmutable;

/** Order cohorts use placed_at; financial events use processed_at. Boundaries are UTC, end-exclusive. */
final readonly class ReportFilterData
{
    public CarbonImmutable $from;

    public CarbonImmutable $until;

    public string $timezone;

    public function __construct(
        public string $dateFrom, public string $dateTo, public ?string $branchUuid = null,
        public ?string $orderType = null, public ?string $orderStatus = null, public ?string $paymentMethod = null,
        public string $groupBy = 'day', public string $sortBy = 'quantity', public int $perPage = 20, public int $page = 1,
    ) {
        $this->timezone = app(RestaurantSettingsService::class)->getString('restaurant.timezone');
        $this->from = CarbonImmutable::parse($dateFrom, $this->timezone)->startOfDay()->utc();
        $this->until = CarbonImmutable::parse($dateTo, $this->timezone)->startOfDay()->addDay()->utc();
    }

    /** Dashboard defaults to today; reports to 30 calendar days ending today (or supplied date_to). */
    public static function fromArray(array $data, bool $dashboard = false): self
    {
        $timezone = app(RestaurantSettingsService::class)->getString('restaurant.timezone');
        $end = $data['date_to'] ?? CarbonImmutable::now($timezone)->toDateString();
        $start = $data['date_from'] ?? CarbonImmutable::parse($end, $timezone)->subDays($dashboard ? 0 : 29)->toDateString();

        return new self($start, $end, $data['branch_uuid'] ?? null, $data['order_type'] ?? null,
            $data['order_status'] ?? null, $data['payment_method'] ?? null,
            $data['group_by'] ?? 'day', $data['sort_by'] ?? 'quantity', (int) ($data['per_page'] ?? 20), (int) ($data['page'] ?? 1));
    }

    public function previous(): self
    {
        $start = $this->from->setTimezone($this->timezone);
        $end = $this->until->setTimezone($this->timezone);
        $days = (int) $start->diffInDays($end);

        return new self($start->subDays($days)->toDateString(), $start->subDay()->toDateString(),
            $this->branchUuid, $this->orderType, $this->orderStatus, $this->paymentMethod,
            $this->groupBy, $this->sortBy, $this->perPage, $this->page);
    }

    public function period(): array
    {
        return ['from' => $this->dateFrom, 'to' => $this->dateTo, 'timezone' => $this->timezone,
            'utc_from' => $this->from->toISOString(), 'utc_until_exclusive' => $this->until->toISOString()];
    }
}
