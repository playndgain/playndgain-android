<?php

namespace App\Networks\Drivers;

use App\Enums\ConnectorCategory;
use App\Models\Offer;
use App\Networks\AbstractDriver;
use App\Networks\Connector;
use App\Networks\Drivers\Concerns\NormalizesFields;
use App\Networks\DTO\ConnectionResult;
use App\Networks\DTO\OfferDTO;
use App\Networks\DTO\PostbackDTO;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * OGAds Private Offer API and a postback URL we write.
 *
 * Their *public* Offer API (`/api/v2`) is visitor-shaped — it wants the
 * visitor's `ip` and `user_agent` and answers with that persona's inventory,
 * which is not something a cron can ask for honestly. This driver uses the
 * Private Offer API instead: `GET {domain}/api/offers?page=N`, no visitor
 * fields, paginated, the same list for everyone. The two take *different*
 * tokens. A private token on `/api/v2` is HTTP 403 `Invalid ability
 * provided.` — an authenticated token without the ability, not a bad key.
 *
 * Every non-2xx and every unparseable body throws. An empty yield read as a
 * catalogue would deactivate every OGAds offer we hold.
 *
 * Rows we refuse to sync, and why (counts from 619 live rows, 2026-08-26):
 *   - `offer_targeting_rules` (3): city / carrier / OS-version rules we have
 *     nowhere to store and nothing to evaluate at wall render. Their own doc
 *     says not to sync what you cannot check.
 *   - No `aff_sub4=` in `tracking_url` (2): those are `\/c\/<jwt>` links with
 *     the real URL sealed inside a signed payload. We cannot put a click id
 *     in one, so every conversion would land as `unknown_click`.
 *   - `payout <= 0` (35): mostly `cpa_percentage` rows they price at zero.
 *     A wall slot that pays the publisher nothing is a wasted user.
 *   - `paused` / `capped` / past `expiration_date` (0 live, but they ship the
 *     flags): nothing to convert today. `upsert()` sets `status = Active` on
 *     every row it sees, so a skipped offer comes back on its own.
 *
 * They sign nothing. Macros that are not in `postbackQuery()` are stripped
 * from the pasted URL. There is no `{transaction_id}` and no `{status}`.
 * Reversals are not documented — every parseable fire is a credit. The
 * controller's source-IP allowlist is the real gate; `postback_key` is an
 * optional literal we can append as `&key=…` (not `{key}` — that would be
 * stripped).
 */
class OGAdsDriver extends AbstractDriver
{
    use NormalizesFields;

    /**
     * Any of their tool domains serves this API. `appsave.space` is already
     * NXDOMAIN despite their "all of our domains will continue to work", so
     * the host is a credential, not a constant.
     */
    private const DEFAULT_DOMAIN = 'downloadlocked.com';

    /** Runaway guard. 619 rows / 1 page live; their own doc illustrates 19. */
    private const MAX_PAGES = 50;

    /** Official offerwall slot. We stamp it on catalogue links too. */
    private const SUBID_PARAM = 'aff_sub4';

    /**
     * `UK` is not ISO-2. Their live sample sends it (`CZ,FR,…,UK,US`).
     * `parseCountries()` would drop it and every British user would miss
     * the offer bought for them.
     */
    private const COUNTRY_ALIASES = [
        'UK' => 'GB',
    ];

    public static function key(): string
    {
        return 'ogads';
    }

    public static function connector(): Connector
    {
        return new Connector('OGAds', ConnectorCategory::Direct, website: 'https://ogads.com');
    }

    /**
     * @return array<string, string>
     */
    public static function postbackQuery(): array
    {
        return [
            'click_id' => '{aff_sub4}',
            'payout' => '{payout}',
            'offer_id' => '{offer_id}',
            'offer_name' => '{offer_name}',
            'ip' => '{session_ip}',
            'datetime' => '{datetime}',
            'session_timestamp' => '{session_timestamp}',
            'affiliate_id' => '{affiliate_id}',
            'source' => '{source}',
            'aff_sub' => '{aff_sub}',
            'aff_sub2' => '{aff_sub2}',
            'aff_sub3' => '{aff_sub3}',
            'aff_sub5' => '{aff_sub5}',
        ];
    }

    /**
     * @return array<string, array{label: string, required?: bool, secret?: bool}>
     */
    public static function credentialSchema(): array
    {
        return [
            'api_key' => [
                'label' => 'Private Offer API token (not the public Offer API key)',
                'required' => true,
                'secret' => true,
            ],
            'api_domain' => [
                'label' => 'Tool domain (optional, default '.self::DEFAULT_DOMAIN.')',
                'required' => false,
            ],
            'postback_key' => [
                'label' => 'Postback secret (optional literal on the pasted URL)',
                'required' => false,
                'secret' => true,
            ],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        $response = $this->catalogue(1);

        if ($response->failed()) {
            // Their 403 body carries `Invalid ability provided.` — a valid
            // token without the right scope, which is a different fix from a
            // wrong key. A bare status code sends people key-hunting.
            return ConnectionResult::fail("OGAds returned HTTP {$response->status()}: ".$this->rejection($response));
        }

        $rows = $response->json('data');

        if (! is_array($rows)) {
            return ConnectionResult::fail('OGAds returned no offer list: '.$this->rejection($response));
        }

        return ConnectionResult::ok('Connected.', (int) ($response->json('meta.total') ?? count($rows)));
    }

    /**
     * Paginated. 1000 rows a page, `meta.last_page` is the stop.
     *
     * A page that fails mid-loop throws out of the generator, and
     * `SyncNetworkOffersJob` records the failure and rethrows before it
     * reaches its deactivation query — a partial pull cannot retire the
     * pages it never read. Do not swallow this to salvage earlier pages.
     *
     * @return iterable<OfferDTO>
     */
    public function fetchOffers(): iterable
    {
        $page = 1;

        do {
            $response = $this->catalogue($page)->throw();
            $rows = $response->json('data');

            if (! is_array($rows)) {
                throw new RuntimeException('OGAds returned no offer list on page '.$page.': '.$this->rejection($response));
            }

            foreach ($rows as $row) {
                if (is_array($row) && $this->syncable($row)) {
                    yield $this->toOffer($row);
                }
            }

            $lastPage = (int) ($response->json('meta.last_page') ?? $page);

            // Clipping the loop and returning would hand the sync job a
            // partial catalogue it has no way to recognise as partial, and
            // everything past the cap would be deactivated as "gone".
            if ($lastPage > self::MAX_PAGES) {
                throw new RuntimeException(
                    'OGAds reported '.$lastPage.' pages, over the '.self::MAX_PAGES.' page cap. Refusing a partial catalogue.'
                );
            }
        } while ($page++ < $lastPage);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function syncable(array $row): bool
    {
        if (! isset($row['id'])) {
            return false;
        }

        // Rules we cannot evaluate. Their doc: "If you cannot check the
        // targeting rules - just do not sync offers that have them."
        if (! empty($row['offer_targeting_rules'])) {
            return false;
        }

        // No subid slot to write into. Filtered on the link shape, not on
        // `payout_type` — today every one of these is a `/c/<jwt>` CPC link,
        // but the sealed payload is a property of the link, not of CPC.
        if (! str_contains((string) ($row['tracking_url'] ?? ''), self::SUBID_PARAM.'=')) {
            return false;
        }

        if ((float) ($row['payout'] ?? 0) <= 0) {
            return false;
        }

        if (! empty($row['paused']) || ! empty($row['capped'])) {
            return false;
        }

        return ! $this->expired($row['expiration_date'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function toOffer(array $row): OfferDTO
    {
        $name = $this->nullableString($row['name'] ?? null) ?? 'Untitled offer';

        return new OfferDTO(
            externalId: (string) $row['id'],
            // Everything after the first " - " is their internal targeting
            // tail (`- US, Incent, Email Submit`) and must not reach a
            // publisher. `Str::before` returns the whole string when the
            // delimiter is absent, and the spaces keep `Coca-Cola` intact.
            name: Str::before($name, ' - '),
            trackingUrl: $this->withSubIdMacro((string) ($row['tracking_url'] ?? '')),
            payout: (float) ($row['payout'] ?? 0),
            description: $this->plaintext($row['description'] ?? null),
            instructions: $this->plaintext($row['instructions'] ?? null),
            iconUrl: $this->nullableString($row['thumbnail'] ?? null),
            countries: $this->countries($row['countries'] ?? []),
            devices: $this->parseDevices($row['devices'] ?? []),
            categories: $this->parseCategories($row['categories'] ?? []),
            conversionType: $this->conversionType($row['payout_type'] ?? null),
        );
    }

    /** `cpa_flat` and `cpa_percentage` are both conversion-priced. */
    private function conversionType(mixed $payoutType): string
    {
        return str_starts_with((string) $payoutType, 'cpa') ? 'cpa' : 'other';
    }

    private function expired(mixed $date): bool
    {
        $date = $this->nullableString($date);

        if ($date === null || str_starts_with($date, '0000-')) {
            return false;
        }

        $timestamp = strtotime($date);

        return $timestamp !== false && $timestamp < time();
    }

    /**
     * @return list<string>
     */
    private function parseCategories(mixed $categories): array
    {
        return collect(is_array($categories) ? $categories : [])
            ->map(fn (mixed $value): string => trim((string) (is_scalar($value) ? $value : '')))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function parsePostback(Request $request): ?PostbackDTO
    {
        $clickId = $this->nullableString($request->input('click_id'))
            ?? $this->nullableString($request->input(self::SUBID_PARAM));
        $payout = $request->input('payout');

        if ($clickId === null || ! is_numeric($payout)) {
            return null;
        }

        $offerId = $this->nullableString($request->input('offer_id'));
        $datetime = $this->nullableString($request->input('datetime'))
            ?? $this->nullableString($request->input('session_timestamp'));

        return new PostbackDTO(
            networkTxId: implode(':', array_filter([$datetime, $offerId, $clickId])),
            clickId: $clickId,
            payout: abs((float) $payout),
            status: 'credited',
            offerExternalId: $offerId,
            offerName: $this->nullableString($request->input('offer_name')),
            ip: $this->nullableString($request->input('ip')),
            raw: $request->all(),
        );
    }

    public function verifyPostback(Request $request): bool
    {
        $expected = $this->credential('postback_key');

        if (blank($expected)) {
            return true;
        }

        return hash_equals($expected, (string) $request->input('key', ''));
    }

    public function buildTrackingUrl(Offer $offer, string $clickId): string
    {
        return str_replace('{click_id}', $clickId, $this->withSubIdMacro($offer->tracking_url));
    }

    private function catalogue(int $page): Response
    {
        $domain = trim($this->credential('api_domain') ?: self::DEFAULT_DOMAIN, " \t/");

        // Without it the locker domain answers with a page, not JSON.
        return $this->http()
            ->withToken((string) $this->credential('api_key'))
            ->acceptJson()
            ->get('https://'.$domain.'/api/offers', ['page' => $page]);
    }

    private function rejection(Response $response): string
    {
        return $this->nullableString($response->json('message'))
            ?? $this->nullableString($response->json('error'))
            ?? 'no error message given.';
    }

    /**
     * @return list<string>
     */
    private function countries(mixed $countries): array
    {
        $codes = collect(is_array($countries) ? $countries : (preg_split('/[,\s|]+/', (string) $countries) ?: []))
            ->map(fn (mixed $code): string => strtoupper(trim((string) (is_scalar($code) ? $code : ''))))
            ->map(fn (string $code): string => self::COUNTRY_ALIASES[$code] ?? $code)
            ->all();

        return $this->parseCountries($codes);
    }

    private function plaintext(mixed $value): ?string
    {
        $text = $this->nullableString($value);

        if ($text === null) {
            return null;
        }

        $text = trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $text === '' ? null : $text;
    }

    /**
     * Every private-API link already ships `aff_sub4=` **empty**. Appending a
     * second one leaves the key twice on the same URL — HasOffers is only
     * assumed to take the last, and a first-wins parser anywhere in the chain
     * would drop every click id we own. Fill the slot they left instead.
     */
    private function withSubIdMacro(string $link): string
    {
        if (str_contains($link, '{click_id}')) {
            return $link;
        }

        $slot = self::SUBID_PARAM.'=';

        if (str_contains($link, $slot)) {
            return (string) preg_replace('/'.preg_quote($slot, '/').'[^&#]*/', $slot.'{click_id}', $link, 1);
        }

        return $link.(str_contains($link, '?') ? '&' : '?').$slot.'{click_id}';
    }
}
