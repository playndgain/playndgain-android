<?php

namespace App\Models;

use App\Enums\AppPlatform;
use App\Enums\AppStatus;
use App\Enums\PostbackMethod;
use App\Models\Concerns\BelongsToPublisher;
use Database\Factories\AppFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class App extends Model
{
    use BelongsToPublisher;

    /** @use HasFactory<AppFactory> */
    use HasFactory;

    use SoftDeletes;

    /** Public IDs start here. This is what goes in the iframe URL. */
    public const FIRST_APP_ID = 10000;

    protected $guarded = [];

    protected $casts = [
        'platform' => AppPlatform::class,
        'status' => AppStatus::class,
        'postback_method' => PostbackMethod::class,
        'secret_key' => 'encrypted',
        'currency_rate' => 'decimal:4',
        'require_signed_wall' => 'boolean',
        'anonymize_ip' => 'boolean',
        'test_mode' => 'boolean',
        'theme' => 'array',
        'blocked_countries' => 'array',
        'approved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $app): void {
            // Same max+1 approach as Publisher::$pub_id. The ID is unique across
            // every publisher, so the lookup must leave the publisher scope —
            // scoped, it saw only this publisher's apps and re-issued an ID
            // another publisher already held.
            $app->app_id ??= (static::withoutGlobalScopes()->withTrashed()->max('app_id') ?? self::FIRST_APP_ID - 1) + 1;
            $app->secret_key ??= Str::random(40);
        });
    }

    /** @return HasMany<OfferClick, $this> */
    public function offerClicks(): HasMany
    {
        return $this->hasMany(OfferClick::class);
    }

    /** @return HasMany<Conversion, $this> */
    public function conversions(): HasMany
    {
        return $this->hasMany(Conversion::class);
    }

    /** @return HasMany<OutgoingPostback, $this> */
    public function outgoingPostbacks(): HasMany
    {
        return $this->hasMany(OutgoingPostback::class);
    }

    /** @return HasMany<DailyStat, $this> */
    public function dailyStats(): HasMany
    {
        return $this->hasMany(DailyStat::class);
    }

    /** @return HasMany<FraudEvent, $this> */
    public function fraudEvents(): HasMany
    {
        return $this->hasMany(FraudEvent::class);
    }

    /** @return HasMany<Blacklist, $this> */
    public function blacklists(): HasMany
    {
        return $this->hasMany(Blacklist::class);
    }

    /** @return HasMany<BannedEndUser, $this> */
    public function bannedEndUsers(): HasMany
    {
        return $this->hasMany(BannedEndUser::class);
    }

    /** @return HasMany<Consent, $this> */
    public function consents(): HasMany
    {
        return $this->hasMany(Consent::class);
    }

    public function isActive(): bool
    {
        return $this->status === AppStatus::Active;
    }
}
