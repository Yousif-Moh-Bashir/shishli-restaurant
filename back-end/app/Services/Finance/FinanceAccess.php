<?php

namespace App\Services\Finance;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class FinanceAccess
{
    public function branches(User $user): Builder
    {
        $query = Branch::query();
        if (! $user->can('finance.branches.all')) {
            $query->whereIn('id', DB::table('finance_branch_user')->where('user_id', $user->id)->select('branch_id'));
        }

        return $query;
    }

    public function branch(User $user, string $uuid): Branch
    {
        return $this->branches($user)->where('uuid', $uuid)->firstOrFail();
    }

    public function scope(Builder $query, User $user): Builder
    {
        return $query->whereIn('branch_id', $this->branches($user)->select('id'));
    }
}
