<?php

namespace App\Models;

use App\Enums\NetworkIntegration;
use App\Enums\NetworkStatus;
use App\Enums\SyncStatus;
use App\Networks\NetworkDriverManager;
use Database\Factories\NetworkFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Throwable;

class Network extends Model
{
    /** @use HasFactory<NetworkFactory> */
    use HasFactory;

    protected $guarded = [];

    /** Never log this, never let it into an exception trace. */
    protected $hidden = ['credentials'];

    protected $casts = [
        'status' => NetworkStatus::class,
        'credentials' => 'encrypted:array',
        'postback_ips' => 'array',
        'allow_any_postback_ip' => 'bool',
        'margin_override' => 'decimal:2',
        'last_synced_at' => 'datetime',
        'last_sync_status' => SyncStatus::class,
        'wall_new_tab' => 'bool',
        'hosts_wall' => 'bool',
        'integration' => NetworkIntegration::class,
    ];

    /**
     * What this partner brings, asked two different ways on purpose.
     *
     * Whether the *driver* has a catalogue is a property of the class —
     * `DirectDriver` says it has none, everything else inherits that it does —
     * and `SyncNetworkOffersJob` already reads it. Whether *we* will call that
     * catalogue is an admin's choice (`integration`). Storing a second "the
     * driver has an API" flag would be free to disagree with the class; storing
     * "do not call it" is what lets an embed-only Ayet row skip the Static API
     * keys and skip the sweep that would otherwise fail on empty credentials.
     *
     * A wall is also an admin's declaration (`hosts_wall`, kept in step with
     * `integration` on save). Derived from `wall_url` it would appear only
     * after the value existed, which is too late to type it.
     */
    public function hostsWall(): bool
    {
        return (bool) $this->hosts_wall;
    }

    public function driverSyncsCatalogue(): bool
    {
        try {
            return app(NetworkDriverManager::class)->make($this)->syncsCatalogue();
        } catch (Throwable) {
            // A driver that was removed, or a row typed by hand. Answering
            // "no catalogue" hides the sync furniture, which is the honest
            // reading — and it must not throw on the way to an admin table
            // that exists to show this row is broken.
            return false;
        }
    }

    public function syncsCatalogue(): bool
    {
        return ($this->integration ?? NetworkIntegration::Api) !== NetworkIntegration::Embed
            && $this->driverSyncsCatalogue();
    }

    /** @return HasMany<Offer, $this> */
    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    /**
     * Partners whose own wall we frame — docs/03 §2.
     *
     * A network qualifies on two conditions, and both are load-bearing: it is
     * switched on, and an admin has pasted the wall URL. Priority sorts the
     * sub-strip, descending, the same direction OfferFeed reads it.
     *
     * `id` breaks the tie because priority defaults to 0, so a tie is the
     * common case rather than the edge one — and the first of these is the
     * partner `/wall/partners` frames. Without it, "the highest-priority
     * partner" is whatever order the database felt like returning.
     *
     * @param  Builder<Network>  $query
     */
    public function scopeEmbeddable(Builder $query): void
    {
        $query->where('status', NetworkStatus::Active)
            ->whereNotNull('wall_url')
            ->orderByDesc('priority')
            ->orderBy('id');
    }

    /** @return HasMany<Conversion, $this> */
    public function conversions(): HasMany
    {
        return $this->hasMany(Conversion::class);
    }

    /** @return HasMany<IncomingPostback, $this> */
    public function incomingPostbacks(): HasMany
    {
        return $this->hasMany(IncomingPostback::class);
    }

    public function isActive(): bool
    {
        return $this->status === NetworkStatus::Active;
    }
}
