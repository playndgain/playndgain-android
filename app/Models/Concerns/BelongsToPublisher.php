<?php

namespace App\Models\Concerns;

use App\Models\Publisher;
use App\Models\Scopes\PublisherScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToPublisher
{
    public static function bootBelongsToPublisher(): void
    {
        static::addGlobalScope(new PublisherScope);
    }

    /** @return BelongsTo<Publisher, $this> */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Publisher::class);
    }
}
