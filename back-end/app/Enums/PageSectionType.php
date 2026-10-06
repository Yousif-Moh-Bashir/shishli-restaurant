<?php

namespace App\Enums;

enum PageSectionType: string
{
    case Hero = 'hero';
    case Text = 'text';
    case ImageText = 'image_text';
    case Features = 'features';
    case Cta = 'cta';
    case Contact = 'contact';
    case CustomData = 'custom_data';
}
