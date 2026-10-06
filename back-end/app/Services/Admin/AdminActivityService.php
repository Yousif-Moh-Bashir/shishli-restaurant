<?php

namespace App\Services\Admin;

use App\Models\AdminActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class AdminActivityService
{
    private const SAFE_KEYS = ['name', 'is_active', 'roles', 'permissions', 'status', 'payment_status', 'is_available', 'is_featured', 'tracks_inventory', 'uuid', 'product_uuids', 'password_changed'];

    public function values(Model $model): array
    {
        $values = Arr::only($model->getAttributes(), self::SAFE_KEYS);
        if ($model instanceof User) {
            $values['roles'] = $model->getRoleNames()->sort()->values()->all();
        }

        return $values;
    }

    public function record(User $actor, string $action, string $subjectType, ?string $uuid, array $old = [], array $new = [], ?string $description = null): AdminActivityLog
    {
        return AdminActivityLog::create(['user_id' => $actor->id, 'action' => $action, 'subject_type' => $subjectType, 'subject_uuid' => $uuid, 'description' => $description,
            'old_values' => $this->sanitize($old), 'new_values' => $this->sanitize($new), 'ip_hash' => null]);
    }

    private function sanitize(array $values): array
    {
        $safe = [];
        foreach (Arr::only($values, self::SAFE_KEYS) as $key => $value) {
            if (in_array($key, ['roles', 'permissions', 'product_uuids'], true)) {
                if (is_array($value)) {
                    $safe[$key] = array_values(array_filter($value, fn (mixed $item): bool => is_string($item)));
                }
            } elseif (is_scalar($value) || $value === null) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }
}
