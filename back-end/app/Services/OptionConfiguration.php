<?php

namespace App\Services;

use App\Enums\OptionGroupType;
use App\Models\OptionGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OptionConfiguration
{
    /** @param array<string, mixed> $data */
    public function validate(array $data): void
    {
        $type = $data['type'] instanceof OptionGroupType ? $data['type'] : OptionGroupType::from($data['type']);
        $minimum = (int) $data['min_select'];
        $maximum = $data['max_select'] === null ? null : (int) $data['max_select'];

        if ($type === OptionGroupType::Single && $maximum !== 1) {
            throw ValidationException::withMessages(['max_select' => 'مجموعة الاختيار الواحد يجب أن تسمح بخيار واحد فقط.']);
        }
        if ($type === OptionGroupType::Single && $minimum !== ((bool) $data['is_required'] ? 1 : 0)) {
            throw ValidationException::withMessages(['min_select' => 'الحد الأدنى للاختيار الواحد يجب أن يكون 1 للمجموعة المطلوبة و0 للاختيارية.']);
        }
        if ((bool) $data['is_required'] && $minimum < 1) {
            throw ValidationException::withMessages(['min_select' => 'المجموعة المطلوبة يجب أن تتطلب خيارًا واحدًا على الأقل.']);
        }
        if ($maximum !== null && $minimum > $maximum) {
            throw ValidationException::withMessages(['max_select' => 'الحد الأقصى يجب ألا يقل عن الحد الأدنى.']);
        }
    }

    /**
     * @param  array<string, mixed>|null  $overrides
     * @return array{type: OptionGroupType, is_required: bool, min_select: int, max_select: ?int}
     */
    public function effective(OptionGroup $group, ?array $overrides = null): array
    {
        $overrides ??= $group->relationLoaded('pivot') ? $group->pivot->getAttributes() : [];
        $maximum = $overrides['max_select_override'] ?? $group->max_select;

        return [
            'type' => $group->type,
            'is_required' => (bool) ($overrides['is_required_override'] ?? $group->is_required),
            'min_select' => (int) ($overrides['min_select_override'] ?? $group->min_select),
            'max_select' => $maximum === null ? null : (int) $maximum,
        ];
    }

    /**
     * No default selection is valid: customers may make the required selection themselves.
     * A nonempty default selection must satisfy the effective minimum and maximum.
     *
     * @param  array<string, mixed>|null  $attachment
     */
    public function assertState(OptionGroup $group, ?array $attachment = null): void
    {
        $active = $group->values()->where('is_active', true)->get(['id', 'is_default']);
        $defaultCount = $active->where('is_default', true)->count();
        $pivots = DB::table('product_option_groups')->where('option_group_id', $group->id)->get();
        $configurations = [$this->effective($group, [])];
        foreach ($pivots as $pivot) {
            $configurations[] = $this->effective($group, (array) $pivot);
        }
        if ($attachment !== null) {
            $configurations[] = $this->effective($group, $attachment);
        }

        foreach ($configurations as $configuration) {
            $this->validate($configuration);
            $maximum = $configuration['max_select'];
            $minimum = $configuration['min_select'];
            if ($defaultCount > 0 && ($defaultCount < $minimum || ($maximum !== null && $defaultCount > $maximum))) {
                throw ValidationException::withMessages(['is_default' => 'عدد الخيارات الافتراضية لا يطابق حدود الاختيار للمجموعة أو لأحد المنتجات المرتبطة.']);
            }
            if ($group->is_active && ($pivots->isNotEmpty() || $attachment !== null) && $active->count() < $minimum) {
                throw ValidationException::withMessages(['values' => 'لا توجد خيارات نشطة كافية لتحقيق الحد الأدنى للمنتجات المرتبطة.']);
            }
        }
    }
}
