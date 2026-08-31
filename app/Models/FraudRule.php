<?php

namespace App\Models;

use App\Enums\FraudAction;
use App\Enums\FraudStage;
use Illuminate\Database\Eloquent\Model;

class FraudRule extends Model
{
    protected $guarded = [];

    protected $casts = [
        'stage' => FraudStage::class,
        'enabled' => 'boolean',
        'action' => FraudAction::class,
        'config' => 'array',
    ];
}
