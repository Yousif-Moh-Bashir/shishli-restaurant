<?php

namespace App\Services\Notifications;

class PhoneNormalizer
{
    public function normalize(string $phone, ?string $country = null): ?string
    {
        $phone = trim($phone);
        if (! preg_match('/^[+0-9() -]+$/D', $phone)) {
            return null;
        }
        $phone = str_replace([' ', '-', '(', ')'], '', $phone);
        if (str_starts_with($phone, '00')) {
            $phone = '+'.substr($phone, 2);
        }
        if ($country === 'SA' && preg_match('/^05[0-9]{8}$/D', $phone)) {
            $phone = '+966'.substr($phone, 1);
        }
        if ($country === 'SA' && preg_match('/^9665[0-9]{8}$/D', $phone)) {
            $phone = '+'.$phone;
        }

        return preg_match('/^\+[1-9][0-9]{7,14}$/D', $phone) ? $phone : null;
    }
}
