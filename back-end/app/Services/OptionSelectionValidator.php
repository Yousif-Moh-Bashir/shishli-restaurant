<?php

namespace App\Services;

use App\Enums\CartIssueCode;
use App\Enums\OptionGroupType;
use App\Models\OptionValue;
use App\Models\Product;
use Illuminate\Support\Collection;

class OptionSelectionValidator
{
    public function __construct(private OptionConfiguration $configuration) {}

    /** @return Collection<int, OptionValue> */
    public function validate(Product $product, array $selections): Collection
    {
        $product->loadMissing('optionGroups.values');
        $groups = $product->optionGroups->keyBy('uuid');
        $submitted = [];
        $selected = collect();
        foreach ($selections as $selection) {
            $key = strtolower($selection['option_group_uuid']);
            if (array_key_exists($key, $submitted)) {
                throw new OptionSelectionException(CartIssueCode::OptionsConfigurationChanged, 'لا يمكن تكرار مجموعة الخيارات.');
            }
            $group = $groups->get($key);
            if ($group === null || ! $group->is_active || $group->trashed()) {
                throw new OptionSelectionException(CartIssueCode::OptionUnavailable, 'مجموعة الخيارات المحددة غير متاحة لهذا المنتج.');
            }
            $uuids = array_map('strtolower', $selection['option_value_uuids']);
            if (count($uuids) !== count(array_unique($uuids))) {
                throw new OptionSelectionException(CartIssueCode::OptionsConfigurationChanged, 'لا يمكن تكرار الخيار.');
            }
            $submitted[$key] = $uuids;
            $values = $group->values->keyBy('uuid');
            foreach ($uuids as $uuid) {
                $value = $values->get($uuid);
                if ($value === null || ! $value->is_active || $value->trashed() || $value->option_group_id !== $group->id) {
                    throw new OptionSelectionException(CartIssueCode::OptionUnavailable, 'أحد الخيارات المحددة غير متاح أو لا ينتمي إلى المجموعة.');
                }
                $selected->push($value->setRelation('optionGroup', $group));
            }
        }
        foreach ($groups as $group) {
            if (! $group->is_active || $group->trashed()) {
                continue;
            }
            $effective = $this->configuration->effective($group);
            $count = count($submitted[$group->uuid] ?? []);
            $minimum = max($effective['min_select'], $effective['is_required'] ? 1 : 0);
            $maximum = $effective['type'] === OptionGroupType::Single ? 1 : $effective['max_select'];
            if ($count < $minimum || ($maximum !== null && $count > $maximum)) {
                throw new OptionSelectionException(CartIssueCode::OptionsConfigurationChanged, 'يرجى اختيار العدد المسموح من مجموعة '.$group->name.'.');
            }
        }

        return $selected->sortBy('id')->values();
    }

    public function signature(Product $product, Collection $values): string
    {
        $ids = $values->pluck('id')->sort()->values()->all();

        return hash('sha256', json_encode([$product->id, $ids], JSON_THROW_ON_ERROR));
    }
}
