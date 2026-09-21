<?php

namespace App\Models;

use App\Enums\NetworkIntegration;
use App\Enums\NetworkStatus;
use App\Enums\SyncStatus;
use App\Networks\Connector;
use App\Networks\Contracts\NetworkDriver;
use App\Networks\NetworkDriverManager;
use App\Networks\OfferCacheFlusher;
use Database\Factories\NetworkFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/** @property-read int|null $conversions_count */
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
     * Callback identity is minted here, not in a form or a seeder.
     *
     * A network with no `postback_key` has no reachable callback URL, and the
     * column is unique and not null, so every way a row can be created — admin,
     * seeder, factory, tinker — has to produce one. Doing it in the model is
     * the only place all four pass through.
     *
     * `account_scope` is the second half of `unique(driver, account_scope)`. It
     * stays empty for a connector that holds one account, which is what keeps
     * the database refusing a second `ogads` row; a platform connector scopes
     * by its own key so its tenants are unique per account rather than per
     * class.
     *
     * The connector is read statically off the driver class: this runs on an
     * unsaved model, and constructing a driver needs a Network that exists.
     * An unregistered driver leaves the scope empty, which is the strict
     * direction — a typo'd key then behaves like a single-account connector
     * rather than silently allowing duplicates.
     *
     * Platform postback secrets are minted here too when the driver declares
     * the field and nobody typed one.
     */
    protected static function booted(): void
    {
        static::creating(function (Network $network): void {
            if (blank($network->postback_key)) {
                $network->postback_key = Str::lower(Str::random(32));
            }

            if (blank($network->account_scope)) {
                $network->account_scope = self::connectorFor((string) $network->driver)?->supportsMultipleAccounts
                    ? (string) $network->postback_key
                    : '';
            }

            $credentials = is_array($network->credentials) ? $network->credentials : [];
            $generated = self::withGeneratedPostbackSecret((string) $network->driver, $credentials);

            if ($generated !== $credentials) {
                $network->credentials = $generated;
            }
        });

        // `conversions.network_id` cascades. A delete that reached the
        // database would take the money history with it, so the model is
        // the last gate every path — panel, tinker, a job — has to pass.
        static::deleting(function (Network $network): void {
            if (! $network->canBeDeleted()) {
                throw new LogicException('A network with conversions cannot be deleted.');
            }

            OfferCacheFlusher::flushForNetwork($network);
        });
    }

    /**
     * Unused accounts can go. One that has been paid through cannot.
     */
    public function canBeDeleted(): bool
    {
        return ($this->conversions_count ?? $this->conversions()->count()) === 0;
    }

    /** Null when the driver is unregistered — a removed class, or a row typed by hand. */
    public function connector(): ?Connector
    {
        return self::connectorFor((string) $this->driver);
    }

    public static function connectorFor(string $driver): ?Connector
    {
        $class = self::driverClass($driver);

        return $class === null ? null : $class::connector();
    }

    /**
     * A platform invents the secret we put on the postback URL. Mint it here
     * so admin create, tinker and a seeder all get one without the form
     * forcing an admin to type 32 random characters.
     *
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    public static function withGeneratedPostbackSecret(string $driver, array $credentials): array
    {
        $class = self::driverClass($driver);

        if ($class === null
            || ! $class::connector()->supportsMultipleAccounts
            || ! array_key_exists('postback_secret', $class::credentialSchema())) {
            return $credentials;
        }

        if (filled($credentials['postback_secret'] ?? null)) {
            return $credentials;
        }

        $credentials['postback_secret'] = Str::random(32);

        return $credentials;
    }

    /** @return class-string<NetworkDriver>|null */
    private static function driverClass(string $driver): ?string
    {
        $manager = app(NetworkDriverManager::class);

        if ($driver === '' || ! $manager->has($driver)) {
            return null;
        }

        return $manager->all()[$driver];
    }

    /**
     * The URL an admin pastes into this account's dashboard.
     *
     * Null rather than a bare path when the driver is unregistered: the macro
     * template is the driver's, and a URL without it is one a partner would
     * accept and then fire with no parameters we read.
     */
    public function postbackUrl(): ?string
    {
        try {
            return app(NetworkDriverManager::class)->make($this)->postbackUrl();
        } catch (Throwable) {
            return null;
        }
    }

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
