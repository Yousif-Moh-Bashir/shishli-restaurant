<?php

namespace App\Enums;

enum OrderTiming: string
{
    case Asap = 'asap';
    case Scheduled = 'scheduled';
}
