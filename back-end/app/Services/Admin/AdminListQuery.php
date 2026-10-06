<?php

namespace App\Services\Admin;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class AdminListQuery
{
    public function apply(Builder $query, array $filters, array $searchable, array $sorts, string $default = 'created_at', string $direction = 'asc'): Builder
    {
        if (isset($filters['search'])) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['search']).'%';
            $query->where(function (Builder $query) use ($pattern, $searchable): void {
                foreach ($searchable as $index => $field) {
                    $index === 0 ? $query->whereRaw($field." LIKE ? ESCAPE '!'", [$pattern]) : $query->orWhereRaw($field." LIKE ? ESCAPE '!'", [$pattern]);
                }
            });
        }
        $active = $filters['active'] ?? $filters['is_active'] ?? null;
        if ($active !== null) {
            $query->where('is_active', (bool) $active);
        }
        $sort = $filters['sort'] ?? $default;
        $direction = $filters['direction'] ?? $direction;
        if (! in_array($sort, $sorts, true) || ! in_array($direction, ['asc', 'desc'], true)) {
            throw ValidationException::withMessages(['sort' => 'Invalid sorting.']);
        }

        return $query->orderBy($sort, $direction)->orderBy($query->getModel()->qualifyColumn('id'), $direction);
    }
}
