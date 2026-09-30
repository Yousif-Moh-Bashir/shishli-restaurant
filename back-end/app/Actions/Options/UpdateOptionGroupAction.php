<?php

namespace App\Actions\Options;

use App\Models\OptionGroup;
use App\Services\OptionConfiguration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateOptionGroupAction
{
    public function __construct(private OptionConfiguration $configuration) {}

    /** @param array<string, mixed> $data */
    public function handle(OptionGroup $group, array $data): OptionGroup
    {
        try {
            return DB::transaction(function () use ($group, $data): OptionGroup {
                $group = OptionGroup::whereKey($group->id)->lockForUpdate()->firstOrFail();
                $group->fill($data);
                $this->configuration->assertState($group);
                $group->save();

                return $group->refresh();
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['slug' => 'الرابط المختصر مستخدم مسبقًا.']);
        }
    }
}
