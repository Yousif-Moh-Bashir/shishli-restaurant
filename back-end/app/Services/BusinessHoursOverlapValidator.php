<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

final class BusinessHoursOverlapValidator
{
    /** Validate the entire circular week, including overnight overlaps across Sunday/Monday. Adjacent intervals are allowed. */
    public function validate(array $hours): void
    {
        $intervals = [];
        foreach ($hours as $index => $hour) {
            $start = $this->minutes($hour['opens_at']);
            $end = $this->minutes($hour['closes_at']);
            if ($start === $end) {
                throw ValidationException::withMessages(['hours.'.$index.'.closes_at' => 'وقت الفتح والإغلاق يجب أن يختلفا.']);
            }
            $offset = ((int) $hour['day_of_week'] - 1) * 1440;
            $intervals[] = ['start' => $offset + $start, 'end' => $offset + $end + ($end < $start ? 1440 : 0), 'index' => $index];
        }
        foreach ($intervals as $i => $first) {
            foreach ($intervals as $j => $second) {
                if ($j <= $i) {
                    continue;
                }
                foreach ([-10080, 0, 10080] as $shift) {
                    if ($first['start'] < $second['end'] + $shift && $first['end'] > $second['start'] + $shift) {
                        throw ValidationException::withMessages(['hours.'.$second['index'] => 'فترات العمل متداخلة.']);
                    }
                }
            }
        }
    }

    private function minutes(string $time): int
    {
        [$hour,$minute] = explode(':', $time);

        return (int) $hour * 60 + (int) $minute;
    }
}
