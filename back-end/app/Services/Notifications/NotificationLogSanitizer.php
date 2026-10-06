<?php

namespace App\Services\Notifications;

class NotificationLogSanitizer
{
    /** Provider responses are reduced to an explicit, small allowlist. */
    public function metadata(array $metadata): array
    {
        $safe = [];
        if (isset($metadata['http_status']) && is_int($metadata['http_status']) && $metadata['http_status'] >= 100 && $metadata['http_status'] <= 599) {
            $safe['http_status'] = $metadata['http_status'];
        }
        if (isset($metadata['delivery_status']) && in_array($metadata['delivery_status'], ['accepted', 'sent', 'delivered', 'rejected'], true)) {
            $safe['delivery_status'] = $metadata['delivery_status'];
        }

        return $safe;
    }

    public function identifier(?string $value): ?string
    {
        return $value !== null && preg_match('/^[a-zA-Z0-9._:@+-]{1,180}$/D', $value) ? $value : null;
    }
}
