<?php

namespace App\Services\Notifications;

class StaffPushEndpoints
{
    public function valid(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            return false;
        }
        $host = strtolower($parts['host'] ?? '');

        // Only browser push services are allowed, never arbitrary server-side URLs.
        return in_array($host, ['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'web.push.apple.com'], true)
            || str_ends_with($host, '.notify.windows.com');
    }
}
