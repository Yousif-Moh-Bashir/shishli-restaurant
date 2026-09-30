<?php

namespace App\Http\Requests\Api\V1\Admin;

class BulkBranchProductsRequest extends AttachBranchProductRequest
{
    protected function prepareForValidation(): void
    {
        $items = $this->input('products');
        if (is_array($items)) {
            foreach ($items as &$item) {
                if (is_array($item) && is_string($item['product_uuid'] ?? null)) {
                    $item['product_uuid'] = strtolower($item['product_uuid']);
                }
            }
            unset($item);
            $this->merge(['products' => $items]);
        }
    }

    public function rules(): array
    {
        $rules = parent::rules();

        return [
            'products' => ['required', 'array', 'min:1', 'max:100'],
            'products.*' => ['required', 'array:product_uuid,price_override,is_available'],
            'products.*.product_uuid' => ['required', 'uuid', 'distinct:ignore_case'],
            'products.*.price_override' => $rules['price_override'],
            'products.*.is_available' => $rules['is_available'],
        ];
    }
}
