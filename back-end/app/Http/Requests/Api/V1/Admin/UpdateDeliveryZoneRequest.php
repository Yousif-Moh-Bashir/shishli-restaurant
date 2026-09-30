<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Services\DeliveryZoneWriter;

class UpdateDeliveryZoneRequest extends StoreDeliveryZoneRequest
{
    public function rules(): array
    {
        return DeliveryZoneWriter::rules(partial: true);
    }
}
