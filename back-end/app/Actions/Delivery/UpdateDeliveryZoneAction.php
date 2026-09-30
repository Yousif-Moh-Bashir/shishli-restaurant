<?php

namespace App\Actions\Delivery;

use App\Models\Branch;
use App\Models\DeliveryZone;
use App\Services\DeliveryZoneWriter;

class UpdateDeliveryZoneAction
{
    public function __construct(private DeliveryZoneWriter $writer) {}

    public function handle(Branch $branch, string $uuid, array $data): DeliveryZone
    {
        return $this->writer->save($branch, $data, $uuid);
    }
}
