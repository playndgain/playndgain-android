<?php

namespace App\Enums;

enum LedgerEntryType: string
{
    case Earning = 'earning';
    case Chargeback = 'chargeback';
    case Withdrawal = 'withdrawal';
    case Adjustment = 'adjustment';
    case Referral = 'referral';
}
