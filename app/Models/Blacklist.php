<?php

namespace App\Models;

use App\Enums\BlacklistType;
use App\Models\Concerns\BelongsToPublisher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Blacklist extends Model
{
    use BelongsToPublisher;

    protected $guarded = [];

    protected $casts = [
        'type' => BlacklistType::class,
    ];

    /** @return BelongsTo<App, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }
}
