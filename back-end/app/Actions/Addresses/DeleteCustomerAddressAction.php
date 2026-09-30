<?php

namespace App\Actions\Addresses;

use App\Models\User;
use App\Services\CustomerAddressWriter;

class DeleteCustomerAddressAction
{
    public function __construct(private CustomerAddressWriter $writer) {}

    public function handle(User $user, string $uuid): void
    {
        $this->writer->transaction($user, function () use ($user, $uuid): void {
            $this->writer->delete($user, $this->writer->owned($user, $uuid));
        });
    }
}
