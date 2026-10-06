<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\PageSectionType;
use App\Rules\PlainText;
use App\Services\CmsSectionDefinition;
use Illuminate\Validation\Validator;

class CmsSectionUpdateRequest extends CmsRequest
{
    public function rules(): array
    {
        $page = $this->route('page');
        $section = $this->route('section');
        $definitions = app(CmsSectionDefinition::class);
        abort_unless(($definitions->sections($page->key)[$section->key] ?? null) === $section->type, 422, 'Unknown CMS section.');
        $dataRules = $definitions->dataRules($page->key, $section->key);
        $keys = array_keys(array_filter($dataRules, fn (string $key): bool => ! str_contains($key, '.'), ARRAY_FILTER_USE_KEY));
        $rules = ['title' => ['sometimes', 'nullable', 'string', 'max:255', new PlainText], 'subtitle' => ['sometimes', 'nullable', 'string', 'max:1000', new PlainText], 'content' => ['sometimes', 'nullable', 'string', 'max:20000', new PlainText],
            'data' => ['sometimes', 'nullable', count($keys) ? 'array:'.implode(',', $keys) : 'array', 'max:12'], 'sort_order' => ['sometimes', 'integer', 'min:0', 'max:2147483647'], 'is_active' => ['sometimes', 'boolean']];
        foreach ($dataRules as $key => $rule) {
            $rules['data.'.$key] = $rule;
        }
        if (in_array($section->type, [PageSectionType::Hero, PageSectionType::ImageText], true)) {
            $rules['image'] = $this->imageRules();
        }

        return $rules;
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            $data = $this->input('data');
            if (is_array($data) && $data !== [] && app(CmsSectionDefinition::class)->dataRules($this->route('page')->key, $this->route('section')->key) === []) {
                $validator->errors()->add('data', 'This section does not accept data.');
            }
        }];
    }
}
