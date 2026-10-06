<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $permissions = $this->resource->getAllPermissions()->pluck('name')->sort()->values()->all();

        return ['id' => $this->uuid, 'name' => $this->name, 'email' => $this->email, 'phone' => $this->phone, 'is_active' => $this->is_active, 'roles' => $this->resource->getRoleNames()->sort()->values()->all(), 'permissions' => $permissions,
            'capabilities' => ['can_view_dashboard' => in_array('dashboard.view', $permissions, true), 'can_manage_orders' => in_array('orders.confirm', $permissions, true), 'can_manage_products' => in_array('products.update', $permissions, true)]];
    }
}
