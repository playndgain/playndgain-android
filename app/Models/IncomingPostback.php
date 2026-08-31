<?php

namespace App\Models;

use App\Enums\IncomingPostbackResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Written before parsing. When a network claims they sent a conversion, this
 * table settles it.
 */
class IncomingPostback extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'query' => 'array',
        'body' => 'array',
        'headers' => 'array',
        'signature_valid' => 'boolean',
        'ip_allowed' => 'boolean',
        'result' => IncomingPostbackResult::class,
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<Network, $this> */
    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }

    /** @return BelongsTo<Conversion, $this> */
    public function conversion(): BelongsTo
    {
        return $this->belongsTo(Conversion::class);
    }

    /**
     * First non-empty scalar among the query keys, in order.
     *
     * Networks disagree on names (`payout` / `payout_usd` / `pay`). The admin
     * table has to read the payload, not wait for a driver to normalise it.
     */
    public function queryValue(string ...$keys): ?string
    {
        $query = $this->query ?? [];

        foreach ($keys as $key) {
            $value = $query[$key] ?? null;

            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * A JSON bag flattened to strings so KeyValueEntry can render it.
     *
     * @param  array<string, mixed>|null  $bag
     * @return array<string, string>
     */
    public function stringMap(?array $bag): array
    {
        return collect($bag ?? [])
            ->map(function (mixed $value): string {
                if (is_scalar($value) || $value === null) {
                    return (string) ($value ?? '');
                }

                return json_encode($value) ?: '';
            })
            ->all();
    }
}
