<?php

namespace App\Enums;

enum FraudActionTaken: string
{
    case Blocked = 'blocked';
    case Flagged = 'flagged';
    case Scored = 'scored';
}
