<?php

namespace App\Services\Admin;

final readonly class AdminProductFilters
{
    public function __construct(public array $values) {}

    public static function fromArray(array $data): self
    {
        foreach (['category_uuid' => 'category', 'active' => 'is_active', 'available' => 'is_available', 'featured' => 'is_featured'] as $alias => $field) {
            if (array_key_exists($alias, $data)) {
                $data[$field] = $data[$alias];
            }
        }

        return new self($data);
    }
}
