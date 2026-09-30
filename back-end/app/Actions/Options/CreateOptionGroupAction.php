<?php

namespace App\Actions\Options;

use App\Enums\OptionGroupType;
use App\Models\OptionGroup;
use App\Services\OptionConfiguration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateOptionGroupAction
{
    public function __construct(private OptionConfiguration $configuration) {}

    /** @param array<string, mixed> $data */
    public function handle(array $data): OptionGroup
    {
        $data += [
            'is_required' => false,
            'min_select' => ! empty($data['is_required']) ? 1 : 0,
            'max_select' => $data['type'] === OptionGroupType::Single->value ? 1 : null,
        ];
        $this->configuration->validate($data);
        for ($attempt = 0; ; $attempt++) {
            try {
                return DB::transaction(fn (): OptionGroup => OptionGroup::create($data)->refresh());
            } catch (UniqueConstraintViolationException $exception) {
                if (filled($data['slug'] ?? null) || $attempt >= 4) {
                    throw ValidationException::withMessages(['slug' => 'الرابط المختصر مستخدم مسبقًا.']);
                }
            }
        }
    }
}
