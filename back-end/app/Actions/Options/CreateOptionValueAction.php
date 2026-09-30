<?php

namespace App\Actions\Options;

use App\Enums\OptionGroupType;
use App\Models\OptionGroup;
use App\Models\OptionValue;
use App\Services\OptionConfiguration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateOptionValueAction
{
    public function __construct(private OptionConfiguration $configuration) {}

    /** @param array<string, mixed> $data */
    public function handle(OptionGroup $group, array $data): OptionValue
    {
        try {
            return DB::transaction(function () use ($group, $data): OptionValue {
                $group = OptionGroup::whereKey($group->id)->lockForUpdate()->firstOrFail();
                if (array_key_exists('is_active', $data) && ! $data['is_active']) {
                    $data['is_default'] = false;
                }
                if (! empty($data['is_default']) && $group->type === OptionGroupType::Single) {
                    $group->values()->update(['is_default' => false]);
                }
                $value = $group->values()->create($data);
                $this->configuration->assertState($group);

                return $value->refresh();
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['slug' => 'الرابط المختصر مستخدم مسبقًا داخل هذه المجموعة.']);
        }
    }
}
