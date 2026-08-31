<?php

namespace App\Enums;

enum OutgoingPostbackKind: string
{
    case Credit = 'credit';
    case Reversal = 'reversal';
}
