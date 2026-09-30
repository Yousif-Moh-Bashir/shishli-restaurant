<?php

namespace App\Actions\Addresses;

use App\Models\CustomerAddress;
use App\Models\User;
use App\Services\CustomerAddressWriter;

class UpdateCustomerAddressAction
{
    public function __construct(private CustomerAddressWriter $writer) {}

    public function handle(User $user, string $uuid, array $data): CustomerAddress
    {
        return $this->writer->transaction($user, fn (): CustomerAddress => $this->writer->save($user, $data, $this->writer->owned($user, $uuid)));
    }
}
