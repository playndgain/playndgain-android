<?php

namespace App\Enums;

enum ConversionType: string
{
    case Cpi = 'cpi';
    case Cpa = 'cpa';
    case Cpl = 'cpl';
    case Survey = 'survey';
    case Signup = 'signup';
    case Purchase = 'purchase';
    case Other = 'other';
}
