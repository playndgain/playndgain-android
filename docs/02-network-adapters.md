# 02 — Network Adapters

How PLAYNDGAIN talks to partner ad networks. One interface, many drivers, zero special cases leaking into the rest of the app.

---

## 1. The Problem This Solves

Eight partner APIs — plus `direct`, the one channel with no API at all, where offers we sell ourselves are typed in by an admin. The eight disagree about everything:

- Auth: query-string API key, `Authorization` header, HMAC-signed request, or basic auth.
- Offer shape: `payout` vs `revenue` vs `amount`; `countries` as an array, a CSV string, or a nested object.
- Targeting: some send an allow-list, some send a deny-list, some send both.
- Postbacks: GET or POST; signature in a param, a header, or absent entirely with an IP allowlist instead.
- Reversals: a separate endpoint, a `status=2` field, or a negative payout on the original transaction ID.

**Nothing above may appear outside `app/Networks/`.** The sync job, the offerwall, and the conversion pipeline see one normalized shape and nothing else.

---

## 2. The Interface

```php
namespace App\Networks\Contracts;

use App\Networks\DTO\OfferDTO;
use App\Networks\DTO\PostbackDTO;
use App\Models\Network;
use Illuminate\Http\Request;

interface NetworkDriver
{
    /** Machine key. Matches networks.driver and the postback route segment. */
    public static function key(): string;

    /** Credential fields this driver needs, for the admin form. */
    public static function credentialSchema(): array;

    /** Cheap auth check for the admin "Test connection" button. */
    public function testConnection(): ConnectionResult;

    /** Full catalogue. Handles the network's own pagination internally. */
    public function fetchOffers(): iterable;   // yields OfferDTO

    /** Parse an inbound postback into our shape. Returns null if unparseable. */
    public function parsePostback(Request $request): ?PostbackDTO;

    /** Is this request genuinely from the network? */
    public function verifyPostback(Request $request): bool;

    /** Put our click_id into the network's subid slot. */
    public function buildTrackingUrl(Offer $offer, string $clickId): string;
}
```

Drivers are constructed with their `Network` model, so credentials come from the encrypted column and never from config or `.env`.

```php
abstract class AbstractDriver implements NetworkDriver
{
    public function __construct(protected Network $network) {}

    protected function credential(string $key): ?string
    {
        return data_get($this->network->credentials, $key);
    }

    protected function http(): PendingRequest
    {
        return Http::timeout(30)
            ->retry(3, 200, throw: false)
            ->withUserAgent('PLAYNDGAIN/1.0');
    }
}
```

### Resolution

```php
class NetworkDriverManager
{
    /** @var array<string, class-string<NetworkDriver>> */
    protected array $drivers = [
        'notik'        => NotikDriver::class,
        'ogads'        => OGAdsDriver::class,
        'monlix'       => MonlixDriver::class,
        'adscendmedia' => AdscendMediaDriver::class,
        'admantium'    => AdmantiumDriver::class,
        'adswedmedia'  => AdswedmediaDriver::class,
        'adgatemedia'  => AdGateMediaDriver::class,
        'lootably'     => LootablyDriver::class,
        'direct'       => DirectDriver::class,
    ];

    public function make(Network $network): NetworkDriver
    {
        $class = $this->drivers[$network->driver]
            ?? throw new UnknownDriverException($network->driver);

        return new $class($network);
    }
}
```

---

## 3. The DTOs

### `OfferDTO`

Every driver's `fetchOffers()` yields these. The sync job accepts nothing else.

```php
final readonly class OfferDTO
{
    public function __construct(
        public string  $externalId,
        public string  $name,
        public string  $trackingUrl,      // must contain the driver's subid macro
        public float   $payout,           // USD, what the network pays us
        public ?string $description = null,
        public ?string $instructions = null,
        public ?string $iconUrl = null,
        public ?string $previewUrl = null,
        public array   $countries = [],   // ISO-2, uppercase. [] = worldwide
        public array   $devices = [],     // android|ios|desktop. [] = all
        public ?string $osMinVersion = null,
        public array   $categories = [],
        public string  $conversionType = 'cpa',
        public bool    $isMultiReward = false,
        public ?int    $dailyCap = null,
    ) {}
}
```

**Normalization is the driver's job, not the sync job's.** Countries arrive uppercase ISO-2 or the driver throws. Payout arrives as USD float or the driver converts it. `"United States"` never reaches the DTO — `"US"` does.

### `PostbackDTO`

```php
final readonly class PostbackDTO
{
    public function __construct(
        public string  $networkTxId,     // idempotency key
        public ?string $clickId,         // our UUID, echoed back via subid
        public float   $payout,          // USD, may be negative on reversal
        public string  $status,          // credited|reversed|rejected|pending
        public ?string $offerExternalId = null,
        public ?string $offerName = null,
        public ?string $endUserId = null,
        public ?string $ip = null,
        public array   $raw = [],
    ) {}
}
```

### `ConnectionResult`

```php
final readonly class ConnectionResult
{
    public function __construct(
        public bool $ok,
        public string $message,
        public ?int $offerCount = null,
    ) {}
}
```

---

## 4. Offer Sync

### Schedule

```php
// routes/console.php
Schedule::command('networks:sync')->everyFifteenMinutes()->withoutOverlapping();
```

`networks:sync` dispatches one `SyncNetworkOffersJob` per network whose `last_synced_at` is older than its `sync_interval_minutes`. Jobs go on the `sync` queue — never the `postbacks` queue, because a slow catalogue pull must never delay a conversion.

### The job

```php
class SyncNetworkOffersJob implements ShouldQueue, ShouldBeUnique
{
    public int $timeout = 600;
    public int $tries = 2;

    public function uniqueId(): string
    {
        return "sync-network-{$this->network->id}";
    }

    public function handle(NetworkDriverManager $manager): void
    {
        $driver  = $manager->make($this->network);
        $runAt   = now();
        $seen    = [];

        try {
            foreach ($driver->fetchOffers() as $dto) {
                $offer = Offer::updateOrCreate(
                    ['network_id' => $this->network->id, 'external_id' => $dto->externalId],
                    $this->mapDto($dto) + ['status' => 'active', 'last_seen_at' => $runAt],
                );

                $seen[] = $offer->id;
            }
        } catch (Throwable $e) {
            $this->network->update([
                'last_sync_status' => 'failed',
                'last_sync_error'  => Str::limit($e->getMessage(), 500),
            ]);

            throw $e;
        }

        // Anything not seen this run is gone from the network's catalogue.
        // Deactivate — never delete. Clicks and conversions reference these rows.
        Offer::where('network_id', $this->network->id)
            ->whereNotIn('id', $seen)
            ->where('status', 'active')
            ->update(['status' => 'inactive']);

        $this->network->update([
            'last_synced_at'   => $runAt,
            'last_sync_status' => 'ok',
            'last_sync_error'  => null,
            'offers_count'     => count($seen),
        ]);

        OfferCacheFlusher::flushForNetwork($this->network);
    }
}
```

### Guard: the empty-catalogue trap

A network returning `200 OK` with an empty array — because the credentials silently expired, or their API is having a bad morning — would deactivate **every offer from that network** and empty the walls of every publisher using it.

```php
// Refuse to act on a suspiciously empty catalogue.
$previous = $this->network->offers_count;

if ($previous > 20 && count($seen) < $previous * 0.2) {
    $this->network->update([
        'last_sync_status' => 'failed',
        'last_sync_error'  => "Catalogue dropped from {$previous} to " . count($seen) . " — refusing to deactivate. Manual review required.",
    ]);

    AdminAlert::raise('network.catalogue_collapse', $this->network);

    return;   // offers keep their previous state
}
```

This guard exists because the failure it prevents is silent, total, and revenue-destroying.

---

## 5. Offer Caching

The wall must not run a filtered query against the full offers table on every render.

**Key:** `offers:app:{app_id}:cc:{country}:dev:{device}`
**TTL:** 300 seconds
**Value:** the rendered offer payload array, already filtered and sorted.

Flushed by tag when: a network syncs, an admin blocks an offer, or a publisher edits a blacklist. Publisher blacklists are part of the key's scope via `app_id`, so one publisher's block never leaks into another's cache.

```php
Cache::tags(["app:{$app->id}", "network:{$networkId}"])->remember($key, 300, fn () => …);
```

Requires the Redis cache driver. Documented in [11-infrastructure.md](11-infrastructure.md) as a hard dependency.

---

## 6. Writing a Driver — Checklist

Each new driver is a self-contained unit. To add one:

1. **Read the network's docs.** Note: auth method, offers endpoint, pagination style, subid parameter name, postback format, reversal signalling.
2. **Create `app/Networks/Drivers/{Name}Driver.php`** extending `AbstractDriver`.
3. **`credentialSchema()`** — return the fields the admin form should render:
   ```php
   public static function credentialSchema(): array
   {
       return [
           'api_key'      => ['label' => 'API Key',      'required' => true,  'secret' => true],
           'publisher_id' => ['label' => 'Publisher ID', 'required' => true],
           'postback_key' => ['label' => 'Postback Secret', 'required' => false, 'secret' => true],
       ];
   }
   ```
4. **`fetchOffers()`** — paginate to exhaustion, `yield` an `OfferDTO` per offer, normalize countries to uppercase ISO-2 and payout to USD.
5. **`buildTrackingUrl()`** — put `$clickId` in the network's subid slot. Get this wrong and conversions arrive with no click to attach to.
6. **`parsePostback()` / `verifyPostback()`** — signature check *and* IP allowlist.
7. **Register** in `NetworkDriverManager::$drivers`.
8. **Seed** a `networks` row with `status = disabled`.
9. **Test** with a recorded fixture (`tests/Fixtures/Networks/{name}-offers.json`) — never a live call.

### Reference implementation sketch

```php
class OGAdsDriver extends AbstractDriver
{
    public static function key(): string { return 'ogads'; }

    public function fetchOffers(): iterable
    {
        $page = 1;

        do {
            $response = $this->http()
                ->withToken($this->credential('api_key'))
                ->get('https://unlockcontent.net/api/v2', [
                    'ctry' => '', 'device' => '', 'page' => $page,
                ])
                ->throw()
                ->json();

            foreach ($response['offers'] ?? [] as $row) {
                yield new OfferDTO(
                    externalId:  (string) $row['offerid'],
                    name:        $row['name_short'] ?: $row['name'],
                    trackingUrl: $row['link'] . '&aff_sub4={click_id}',
                    payout:      (float) $row['payout'],
                    description: $row['description'] ?? null,
                    instructions:$row['adcopy'] ?? null,
                    iconUrl:     $row['picture'] ?? null,
                    countries:   $this->parseCountries($row['country'] ?? ''),
                    devices:     $this->parseDevices($row['device'] ?? ''),
                    conversionType: 'cpa',
                );
            }

            $page++;
        } while (! empty($response['offers']));
    }

    public function buildTrackingUrl(Offer $offer, string $clickId): string
    {
        return str_replace('{click_id}', $clickId, $offer->tracking_url);
    }
}
```

> Every registered driver talks to a real endpoint. Between them they cover the four common auth patterns: bearer token (OGAds), query-string key (Notik, AdGate, Adswedmedia, Admantum, Monlix's md5 token), HTTP basic (AdscendMedia), and POSTed credentials (Lootably).
>
> **The catalogue half is ported from working integrations; the postback half is not.** Every driver's `parsePostback()`/`verifyPostback()` carries an `ASSUMPTION` comment naming what still needs checking against a live fire — parameter names, the reversal signal, and the signature recipe. Verify those before a network takes live traffic: a wrong reversal reading credits a chargeback, and a wrong signature recipe rejects every postback while looking exactly like a network outage.
>
> A network with no adapter behind it is not registered and not seeded. An enabled row that syncs nothing is worse than a missing one.

### `direct` — offers we sell ourselves

Manual offers are not a second kind of offer. They are rows on the seeded `direct` network, so clicks, postbacks, the fraud pass, the ledger and publisher postbacks all run the same code a network's offer runs.

An admin adds one from **Offers → Add offer**. The tracking URL is the advertiser's, and must contain `{click_id}`; nothing appends it silently, because an untracked click is an unpaid conversion. `external_id` is generated (`direct-…`) since the advertiser has no id of their own to give us.

`DirectDriver::syncsCatalogue()` returns **false** and `SyncNetworkOffersJob` checks it before anything else. The network has to be `active` to accept postbacks, and `networks:sync` sweeps every active network — an empty catalogue would otherwise read as "the network returned nothing" and deactivate every manual offer on it.

The postback contract is ours to define rather than a network's to guess, so it is a specification handed to the advertiser, not an assumption:

```
POST|GET https://{host}/postback/direct
  transaction_id   required   their unique id — the idempotency key
  click_id         required   echoed from our tracking link
  payout           required   USD
  secret           required   the network's Postback Secret
  status           optional   2 or "reversed" reverses the conversion
  offer_id         optional   their own offer id
```

There is no click+offer fallback idempotency key here, unlike OGAds and AdGate where the network omits an id: we write this contract, and a duplicate credit is money that does not come back. The source-IP allowlist applies as it does to every network — both gates, not either.

---

## 7. Deduplication Across Networks

The same offer (say, *Coin Master — Android — US*) often exists on three networks at different payouts. Showing it three times looks broken and cannibalizes conversion rate.

**v1 rule:** group active offers by a fuzzy key — `slug(name) + primary country + device` — and show only the **highest-payout** row, breaking ties by `networks.priority`.

```php
$offers = $offers
    ->groupBy(fn ($o) => Str::slug($o->name) . '|' . $o->primary_country . '|' . $o->primary_device)
    ->map(fn ($group) => $group->sortByDesc(fn ($o) => [$o->payout, $o->network->priority])->first())
    ->values();
```

This is deliberately crude. It is applied at cache-build time, so the cost is paid once per five minutes, not per request. A proper matching model is a v2 concern — noted, not built.

---

## 8. Testing

- **`FakeNetworkDriver`** — implements the full interface, returns fixture offers, accepts a scripted postback. Every conversion-pipeline test uses it. Nothing else touches HTTP.
- **Per-driver fixture tests** — `fetchOffers()` against a recorded JSON response, asserting the resulting `OfferDTO` shape: countries uppercase ISO-2, payout a positive float, tracking URL containing the subid macro.
- **Postback parse tests** — a recorded real postback per driver, asserting the `PostbackDTO` fields and that a tampered signature fails `verifyPostback()`.
- **Collapse-guard test** — a network with 100 offers returning 3 does not deactivate anything and raises the alert.
