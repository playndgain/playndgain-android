<?php

namespace App\Models;

use App\Enums\OutgoingPostbackKind;
use App\Enums\OutgoingPostbackStatus;
use App\Enums\PostbackMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutgoingPostback extends Model
{
    public const UPDATED_AT = null;

    /** Publishers return HTML error pages; anything past this is noise. */
    public const RESPONSE_BODY_LIMIT = 2048;

    protected $guarded = [];

    protected $casts = [
        'kind' => OutgoingPostbackKind::class,
        'method' => PostbackMethod::class,
        'payload' => 'array',
        'status' => OutgoingPostbackStatus::class,
        'next_retry_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<Conversion, $this> */
    public function conversion(): BelongsTo
    {
        return $this->belongsTo(Conversion::class);
    }

    /** @return BelongsTo<App, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }
}
