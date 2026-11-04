<?php

namespace App\Http\Resources\Finance;

use App\Models\Finance\RevenueDay;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecordResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $record = $this->resource;
        $values = $record->toArray();
        unset($values['branch'], $values['creator'], $values['approver']);
        $values['branch'] = $record->branch ? ['uuid' => $record->branch->uuid, 'name' => $record->branch->name] : null;
        $values['creator'] = $record->creator ? ['uuid' => $record->creator->uuid, 'name' => $record->creator->name] : null;
        if ($record instanceof RevenueDay) {
            $values['approver'] = $record->approver ? ['uuid' => $record->approver->uuid, 'name' => $record->approver->name] : null;
        } else {
            $values['has_attachment'] = $record->attachment_path !== null;
        }

        return $values;
    }
}
