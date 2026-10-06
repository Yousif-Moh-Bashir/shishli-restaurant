<?php

namespace App\Services\Admin;

final readonly class AdminOrderFilters
{
    public function __construct(public array $values) {}

    public static function fromArray(array $values): self
    {
        if (isset($values['branch_uuid'])) {
            $values['branch'] = $values['branch_uuid'];
        }

        return new self($values);
    }
}
