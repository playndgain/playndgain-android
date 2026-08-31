<?php

namespace App\Enums;

enum DeviceType: string
{
    case Android = 'android';
    case Ios = 'ios';
    case Desktop = 'desktop';
    case Other = 'other';
}
