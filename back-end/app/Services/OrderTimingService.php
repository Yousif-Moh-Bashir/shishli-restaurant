<?php

namespace App\Services;

use App\Enums\OrderTiming;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class OrderTimingService
{
    /** Scheduled inputs require an explicit UTC offset, stored in UTC. No capacity/reservation workflow is implied. */
    public function resolve(array $data, array $settings): array
    {
        $timing = OrderTiming::tryFrom($data['order_timing'] ?? 'asap');
        if ($timing === null) {
            throw ValidationException::withMessages(['order_timing' => 'توقيت الطلب غير صالح.']);
        }
        if ($timing === OrderTiming::Asap) {
            if (isset($data['scheduled_for'])) {
                throw ValidationException::withMessages(['scheduled_for' => 'وقت الجدولة متاح للطلبات المجدولة فقط.']);
            }

            return [$timing, CarbonImmutable::now()->utc()];
        }
        if (! $settings['orders.allow_scheduled_orders']) {
            throw ValidationException::withMessages(['order_timing' => 'الطلبات المجدولة غير متاحة.']);
        }
        $input = $data['scheduled_for'] ?? '';
        if (! is_string($input) || ! preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?(?:Z|[+-]\\d{2}:\\d{2})$/D', $input)) {
            throw ValidationException::withMessages(['scheduled_for' => 'أرسل وقتًا بصيغة ISO 8601 مع المنطقة الزمنية.']);
        }
        try {
            $at = CarbonImmutable::parse($input)->utc();
        } catch (\Exception) {
            throw ValidationException::withMessages(['scheduled_for' => 'وقت الجدولة غير صالح.']);
        }
        $now = CarbonImmutable::now($settings['restaurant.timezone']);
        if ($at->lessThanOrEqualTo($now) || $at->greaterThan($now->addDays($settings['orders.max_scheduling_days']))) {
            throw ValidationException::withMessages(['scheduled_for' => 'وقت الجدولة يجب أن يكون مستقبليًا وضمن المدة المسموحة.']);
        }

        return [$timing, $at];
    }
}
