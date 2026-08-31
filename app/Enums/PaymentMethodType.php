<?php

namespace App\Enums;

enum PaymentMethodType: string
{
    case Paypal = 'paypal';
    case Crypto = 'crypto';
    case Bank = 'bank';
    case Wise = 'wise';
}
