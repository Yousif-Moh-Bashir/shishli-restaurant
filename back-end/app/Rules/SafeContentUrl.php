<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SafeContentUrl implements ValidationRule
{
    public function __construct(private bool $absoluteOnly = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->safe($value)) {
            $fail('The :attribute must be a safe HTTP(S) URL or internal path.');
        }
    }

    private function safe(string $value): bool
    {
        $decoded = html_entity_decode(rawurldecode(rawurldecode($value)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('/[\\x00-\\x20\\x7F<>"\\\\]/u', $decoded) || str_starts_with($decoded, '//')) {
            return false;
        }
        if (! $this->absoluteOnly && str_starts_with($decoded, '/')) {
            return ! in_array('..', explode('/', parse_url($decoded, PHP_URL_PATH) ?? ''), true);
        }
        $parts = parse_url($decoded);

        return filter_var($decoded, FILTER_VALIDATE_URL) !== false && is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) && ! isset($parts['user']) && ! isset($parts['pass']);
    }
}
