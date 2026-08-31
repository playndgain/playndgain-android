<?php

namespace App\Enums;

enum WithdrawalStatus: string
{
    case Requested = 'requested';
    case Processing = 'processing';
    case Paid = 'paid';
    case Rejected = 'rejected';
}
