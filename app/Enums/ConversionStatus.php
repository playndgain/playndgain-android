<?php

namespace App\Enums;

enum ConversionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Reversed = 'reversed';
    case Rejected = 'rejected';
}
