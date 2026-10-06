<?php

namespace App\Enums;

enum NotificationChannel: string
{
    case Sms = 'sms';
    case WhatsApp = 'whatsapp';
    case Email = 'email';
}
