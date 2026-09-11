<?php

namespace App\Models;

use App\Enums\IdentityKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdentitySession extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'kind' => IdentityKind::class,
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Publisher, $this> */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Publisher::class);
    }

    /** @return BelongsTo<App, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }
}
