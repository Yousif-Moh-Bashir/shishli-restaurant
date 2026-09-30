<?php

namespace App\Services;

use App\Models\CustomerAddress;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerAddressWriter
{
    public function transaction(User $user, Closure $operation): mixed
    {
        return DB::transaction(function () use ($user, $operation): mixed {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            return $operation();
        }, 3);
    }

    public function owned(User $user, string $uuid): CustomerAddress
    {
        return $user->addresses()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
    }

    public function save(User $user, array $data, ?CustomerAddress $address = null): CustomerAddress
    {
        $address ??= new CustomerAddress;
        $address->fill($data);
        if (($address->latitude === null) !== ($address->longitude === null)) {
            throw ValidationException::withMessages(['latitude' => 'يجب إدخال خط العرض وخط الطول معًا أو مسحهما معًا.']);
        }
        if (! $address->exists && ! $user->addresses()->exists()) {
            $address->is_default = true;
        }
        if ($address->is_default) {
            $user->addresses()->when($address->exists, fn ($query) => $query->whereKeyNot($address->id))->update(['is_default' => false]);
        }
        $address->user()->associate($user);
        $address->save();

        return $address->refresh();
    }

    public function delete(User $user, CustomerAddress $address): void
    {
        $wasDefault = $address->is_default;
        $address->update(['is_default' => false]);
        $address->delete();
        if ($wasDefault) {
            $replacement = $user->addresses()->orderByDesc('id')->first();
            $replacement?->update(['is_default' => true]);
        }
    }
}
