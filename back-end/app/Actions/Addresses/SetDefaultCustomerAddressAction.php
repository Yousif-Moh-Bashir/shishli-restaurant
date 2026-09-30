<?php

namespace App\Actions\Addresses;

use App\Models\CustomerAddress;
use App\Models\User;
use App\Services\CustomerAddressWriter;

class SetDefaultCustomerAddressAction
{
    public function __construct(private CustomerAddressWriter $writer) {}

    public function handle(User $user, string $uuid): CustomerAddress
    {
        return $this->writer->transaction($user, fn (): CustomerAddress => $this->writer->save($user, ['is_default' => true], $this->writer->owned($user, $uuid)));
    }
}
