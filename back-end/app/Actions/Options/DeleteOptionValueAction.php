<?php

namespace App\Actions\Options;

use App\Models\OptionGroup;
use App\Models\OptionValue;
use App\Services\OptionConfiguration;
use Illuminate\Support\Facades\DB;

class DeleteOptionValueAction
{
    public function __construct(private OptionConfiguration $configuration) {}

    public function handle(OptionGroup $group, OptionValue $value): void
    {
        DB::transaction(function () use ($group, $value): void {
            $group = OptionGroup::whereKey($group->id)->lockForUpdate()->firstOrFail();
            $value = $group->values()->whereKey($value->id)->firstOrFail();
            $value->delete();
            $this->configuration->assertState($group);
        }, 3);
    }
}
