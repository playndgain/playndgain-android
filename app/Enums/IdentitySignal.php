<?php

namespace App\Enums;

enum IdentitySignal: string
{
    case Ip = 'ip';
    case Ds = 'ds';
    case Session = 'session';
}
