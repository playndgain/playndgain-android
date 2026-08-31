<?php

namespace App\Enums;

enum NetworkStatus: string
{
    case Disabled = 'disabled';
    case Active = 'active';
    case Error = 'error';
}
