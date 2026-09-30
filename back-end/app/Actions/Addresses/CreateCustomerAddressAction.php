<?php

namespace App\Actions\Addresses;

use App\Models\CustomerAddress;
use App\Models\User;
use App\Services\CustomerAddressWriter;

class CreateCustomerAddressAction
{
    public function __construct(private CustomerAddressWriter $writer) {}

    public function handle(User $user, array $data): CustomerAddress
    {
        return $this->writer->transaction($user, fn (): CustomerAddress => $this->writer->save($user, $data));
    }
}
