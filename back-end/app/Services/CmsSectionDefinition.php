<?php

namespace App\Services;

use App\Enums\PageSectionType as Type;
use App\Rules\PlainText;
use App\Rules\SafeContentUrl;

class CmsSectionDefinition
{
    public function pages(): array
    {
        return ['home' => 'الرئيسية', 'about' => 'من نحن', 'contact' => 'تواصل معنا', 'gallery' => 'معرض الصور'];
    }

    public function sections(string $page): array
    {
        return match ($page) {
            'home' => ['hero' => Type::Hero, 'about_preview' => Type::ImageText, 'features' => Type::Features, 'featured_menu' => Type::CustomData, 'offers' => Type::CustomData, 'gallery_preview' => Type::CustomData, 'location' => Type::Contact, 'cta' => Type::Cta],
            'about' => ['hero' => Type::Hero, 'story' => Type::ImageText, 'values' => Type::Features, 'quality' => Type::Text, 'cta' => Type::Cta],
            'contact' => ['hero' => Type::Hero, 'contact_intro' => Type::Text, 'location' => Type::Contact, 'working_hours' => Type::Contact],
            'gallery' => ['hero' => Type::Hero, 'intro' => Type::Text], default => []
        };
    }

    public function dataRules(string $page, string $key): array
    {
        $type = $this->sections($page)[$key] ?? null;
        abort_if($type === null, 422, 'Unknown CMS section.');
        $text = ['nullable', 'string', 'max:100', new PlainText];
        $rules = [];
        if (in_array($type, [Type::Hero, Type::Cta, Type::ImageText], true)) {
            $rules = ['primary_button_text' => $text, 'primary_button_url' => ['nullable', 'string', 'max:2048', new SafeContentUrl], 'secondary_button_text' => $text, 'secondary_button_url' => ['nullable', 'string', 'max:2048', new SafeContentUrl]];
        }
        if (in_array($key, ['featured_menu', 'offers', 'gallery_preview'], true)) {
            $rules['limit'] = ['sometimes', 'integer', 'min:1', 'max:'.($key === 'gallery_preview' ? '12' : '24')];
        }
        if ($type === Type::Features) {
            $rules['items'] = ['sometimes', 'array', 'max:12'];
            $rules['items.*'] = ['array:title,description'];
            $rules['items.*.title'] = ['required', 'string', 'max:150', new PlainText];
            $rules['items.*.description'] = ['nullable', 'string', 'max:1000', new PlainText];
        }

        return $rules;
    }
}
