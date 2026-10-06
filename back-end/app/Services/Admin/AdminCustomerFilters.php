<?php

namespace App\Services\Admin;

final readonly class AdminCustomerFilters
{
    public function __construct(public array $values) {}

    public static function fromArray(array $data): self
    {
        foreach (['active' => 'is_active', 'date_from' => 'registered_from', 'date_to' => 'registered_to'] as $alias => $field) {
            if (array_key_exists($alias, $data)) {
                $data[$field] = $data[$alias];
            }
        }

        return new self($data);
    }
}
