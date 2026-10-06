<?php

namespace App\Actions\Branches;

use App\Enums\DayOfWeek;
use App\Models\Branch;
use App\Services\BusinessHoursOverlapValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ReplaceBusinessHoursAction
{
    public function __construct(private BusinessHoursOverlapValidator $overlap) {}

    /** PUT replaces the whole week, including an empty week; omitted days become closed. */
    public function handle(Branch $branch, array $hours): Branch
    {
        Validator::make(['hours' => $hours], [
            'hours' => ['present', 'array', 'max:100'],
            'hours.*' => ['array:day_of_week,opens_at,closes_at'],
            'hours.*.day_of_week' => ['required', 'integer', Rule::enum(DayOfWeek::class)],
            'hours.*.opens_at' => ['required', 'date_format:H:i'],
            'hours.*.closes_at' => ['required', 'date_format:H:i'],
        ])->validate();
        $this->overlap->validate($hours);

        return DB::transaction(function () use ($branch, $hours): Branch {
            $branch = Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $branch->businessHours()->delete();
            $branch->businessHours()->createMany($hours);

            return $branch->load('businessHours');
        }, 3);
    }
}
