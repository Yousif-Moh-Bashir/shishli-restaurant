<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PlainText implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && (preg_match('/<[^>]*>|[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]/u', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')) || preg_match('/(?:javascript|vbscript)\\s*:/i', $value))) {
            $fail('The :attribute must contain plain text without markup or scripts.');
        }
    }
}
