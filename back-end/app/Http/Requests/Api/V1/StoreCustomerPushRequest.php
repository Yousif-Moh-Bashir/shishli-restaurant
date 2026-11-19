<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Admin\StoreStaffPushSubscriptionRequest;

class StoreCustomerPushRequest extends StoreStaffPushSubscriptionRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active === true;
    }
}
