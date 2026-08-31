<?php

namespace App\Enums;

enum FraudStage: string
{
    case PreClick = 'pre_click';
    case PostConversion = 'post_conversion';
}
