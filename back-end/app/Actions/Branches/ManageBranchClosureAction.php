<?php

namespace App\Actions\Branches;

use App\Models\Branch;
use App\Models\BranchClosure;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ManageBranchClosureAction
{
    public function save(Branch $branch, array $data, User $actor, ?BranchClosure $closure = null): BranchClosure
    {
        return DB::transaction(function () use ($branch, $data, $actor, $closure): BranchClosure {
            $branch = Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $closure = $closure === null ? new BranchClosure : $branch->closures()->whereKey($closure->id)->lockForUpdate()->firstOrFail();
            $merged = array_replace($closure->exists ? $closure->only(['reason', 'is_active']) : ['is_active' => true], $data);
            foreach (['starts_at', 'ends_at'] as $field) {
                if (isset($data[$field])) {
                    $merged[$field] = CarbonImmutable::parse($data[$field])->utc();
                } elseif ($closure->exists) {
                    $merged[$field] = $closure->$field;
                }
            }
            Validator::make($merged, ['starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'],
                'reason' => ['nullable', 'string', 'max:1000'], 'is_active' => ['sometimes', 'boolean']])->validate();
            if ($merged['ends_at']->lessThanOrEqualTo($merged['starts_at'])) {
                throw ValidationException::withMessages(['ends_at' => 'نهاية الإغلاق يجب أن تكون بعد بدايته.']);
            }
            $closure->fill($merged);
            if (! $closure->exists) {
                $closure->created_by = $actor->id;
            }
            $branch->closures()->save($closure);

            return $closure;
        }, 3);
    }

    public function delete(Branch $branch, BranchClosure $closure): void
    {
        DB::transaction(function () use ($branch, $closure): void {
            Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $branch->closures()->whereKey($closure->id)->firstOrFail()->delete();
        }, 3);
    }
}
