<?php

namespace App\Enums;

enum FraudAction: string
{
    case Block = 'block';
    case Flag = 'flag';
    case Score = 'score';
}
