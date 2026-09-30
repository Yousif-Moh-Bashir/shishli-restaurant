<?php

namespace App\Actions\Options;

use App\Enums\OptionGroupType;
use App\Models\OptionGroup;
use App\Models\OptionValue;
use App\Services\OptionConfiguration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateOptionValueAction
{
    public function __construct(private OptionConfiguration $configuration) {}

    /** @param array<string, mixed> $data */
    public function handle(OptionGroup $group, OptionValue $value, array $data): OptionValue
    {
        try {
            return DB::transaction(function () use ($group, $value, $data): OptionValue {
                $group = OptionGroup::whereKey($group->id)->lockForUpdate()->firstOrFail();
                $value = $group->values()->whereKey($value->id)->firstOrFail();
                $value->fill($data);
                if (! $value->is_active) {
                    $value->is_default = false;
                }
                if ($value->is_default && $group->type === OptionGroupType::Single) {
                    $group->values()->whereKeyNot($value->id)->update(['is_default' => false]);
                }
                $value->save();
                $this->configuration->assertState($group);

                return $value->refresh();
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['slug' => 'الرابط المختصر مستخدم مسبقًا داخل هذه المجموعة.']);
        }
    }
}
