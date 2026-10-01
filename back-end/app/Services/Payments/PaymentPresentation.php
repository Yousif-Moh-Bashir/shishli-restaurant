<?php
namespace App\Services\Payments;
class PaymentPresentation
{
    public function checkoutUrl(?string $url): ?string
    {
        if ($url === null || strlen($url) > 2048) { return null; }
        $parts = parse_url($url);
        return is_array($parts) && ($parts['scheme'] ?? null) === 'https'
            && ! empty($parts['host']) && ! isset($parts['user']) && ! isset($parts['pass']) ? $url : null;
    }
}
