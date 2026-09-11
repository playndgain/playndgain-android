<?php

namespace Database\Seeders;

use App\Enums\NetworkIntegration;
use App\Enums\NetworkStatus;
use App\Models\Network;
use Illuminate\Database\Seeder;

/**
 * Every network is seeded `disabled` with empty credentials. An admin activates
 * one by pasting credentials and hitting Test connection.
 *
 * One row per driver we can actually talk to. A network with no adapter behind
 * it is not seeded: a row an admin can fill in and enable, that then syncs
 * nothing, is worse than no row at all.
 *
 * **Local and tests only.** Production accounts are created from
 * **Networks → New network**. This class is not in `DatabaseSeeder`, and
 * `run()` is a no-op in production so `db:seed --class=NetworkSeeder` cannot
 * put the launch set back after an admin deleted it.
 *
 * **This is the launch set, not the full driver set.** The partners below
 * are the ones being applied to first, in the order they are worth pursuing;
 * `adswedmedia` and `lootably` keep their drivers and fixture-backed tests but
 * are deliberately not seeded, because a row for a network we have no account
 * with is an invitation to enable something that cannot earn. Adding one later
 * is a row on New network, not a code change.
 */
class NetworkSeeder extends Seeder
{
    /** Where the logos live, relative to `public/`. */
    private const LOGO_PATH = 'img/networks/';

    private const NETWORKS = [
        ['driver' => 'adgatemedia', 'name' => 'AdGate', 'website' => 'https://adgatemedia.com', 'logo' => 'adgatemedia.svg'],
        ['driver' => 'admantium', 'name' => 'Admantum', 'website' => 'https://admantum.com', 'logo' => 'admantium.svg'],
        ['driver' => 'torox', 'name' => 'Torox', 'website' => 'https://torox.io', 'logo' => 'torox.png'],
        ['driver' => 'monlix', 'name' => 'Monlix', 'website' => 'https://monlix.com', 'logo' => 'monlix.png'],
        ['driver' => 'adscendmedia', 'name' => 'Adscend Media', 'website' => 'https://adscendmedia.com', 'logo' => 'adscendmedia.webp'],
        // All six from their IP whitelisting page, and the count is the point.
        // An earlier version of this row carried only the three AWS addresses,
        // which is the failure that costs money rather than the one that looks
        // broken: the network works, conversions arrive, and the ones fired
        // from the three OVH addresses are rejected at the door and never paid.
        // A partial allowlist is worse than an empty one — an empty one fails
        // completely and gets noticed on the first conversion.
        [
            'driver' => 'ayetstudios',
            'name' => 'Ayet Studios',
            'website' => 'https://www.ayetstudios.com',
            'logo' => 'ayetstudios.webp',
            'postback_ips' => [
                '51.79.101.241',
                '158.69.185.134',
                '158.69.185.154',
                '35.165.166.40',
                '35.166.159.131',
                '52.40.3.140',
            ],
        ],
        // They publish one firing address and sign the whole callback URL.
        // Both gates are real; the IP is seeded so the first conversion is
        // not rejected at the door while someone looks up the docs.
        [
            'driver' => 'notik',
            'name' => 'Notik',
            'website' => 'https://notik.me',
            'logo' => 'notik.png',
            'postback_ips' => ['158.69.116.45'],
        ],
        // Everflow underneath. They publish nine S2S egress addresses — the
        // whole of the authentication, because Adtogame signs nothing. A
        // partial list is the failure that costs money: conversions from a
        // missing address are rejected at the door and never paid.
        [
            'driver' => 'adtogame',
            'name' => 'Adtogame',
            'website' => 'https://adtogame.com',
            'logo' => 'adtogame.png',
            'postback_ips' => [
                '35.196.95.104',
                '34.138.191.250',
                '34.138.95.128',
                '34.91.131.78',
                '34.147.37.169',
                '34.147.67.230',
                '34.73.83.118',
                '35.227.72.33',
                '34.74.122.67',
            ],
        ],
        // The best-documented postback of the set: GemiAd signs it with
        // sha256(userId + offerId + txId + secretKey) and publishes the single
        // address it fires from, seeded below. Both gates are real here, which
        // is why it sorts above the least-proven partner rather than beside
        // it — but the postback URL itself still has to be pasted into their
        // placement settings by hand, and the template to paste is on the
        // network edit screen. Until it is, this network converts nothing.
        [
            'driver' => 'gemiad',
            'name' => 'GemiAd',
            'website' => 'https://gemiwall.com',
            'logo' => 'gemiad.svg',
            'postback_ips' => ['64.226.92.208'],
        ],
        // Documented on both halves: an unpaginated catalogue behind one API
        // key, and a postback they sign with md5(subId + transId + reward +
        // secretKey). What is missing is the same thing Adtogame is missing —
        // they publish no firing addresses, so the allowlist below stays empty
        // and any-source stays on until someone pastes a list and turns it off.
        ['driver' => 'offery', 'name' => 'Offery', 'website' => 'https://offery.io', 'logo' => 'offery.svg'],
        // Postback macros are documented (Hang My Tracking Optional Variables).
        // They still publish no firing addresses and no signature, so the
        // allowlist starts empty and rejects until someone pastes their IPs.
        // `{pay}` is USD; `{payout}` is cents. Fire a live test before traffic.
        ['driver' => 'hangmyads', 'name' => 'HangMyAds', 'website' => 'https://hangmyads.com', 'logo' => 'hangmyads.png'],
        // Catalogue plus their hosted iframe. They sign nothing and publish
        // six firing addresses, seeded below. Auth is Bearer `{pub_id}:{api_key}`.
        // Replace YOUR_PUB_ID on the wall URL before framing it.
        [
            'driver' => 'klink',
            'name' => 'Klink',
            'website' => 'https://publisher.klinklabs.com',
            'logo' => 'klink.png',
            'hosts_wall' => true,
            'integration' => NetworkIntegration::Both,
            'wall_url' => 'https://offerwall.klinkfinance.com/wall?pub_id=YOUR_PUB_ID&user_id={sub_id}',
            'postback_ips' => [
                '34.118.33.53',
                '138.68.125.171',
                '64.226.93.56',
                '74.220.53.15',
                '74.220.53.234',
                '74.220.53.235',
            ],
        ],
        // Catalogue API plus their hosted iframe. They HMAC-SHA256 the
        // postback (`user_id=&oid=&amount=&tx_id=`) and publish no firing
        // addresses, so the allowlist starts empty and any-source stays on.
        // API access is request-and-approve; the iframe works without it.
        // Replace YOUR_APP_ID on the wall URL before framing it.
        [
            'driver' => 'clickwall',
            'name' => 'ClickWall',
            'website' => 'https://clickwall.net',
            'logo' => 'clickwall.png',
            'hosts_wall' => true,
            'integration' => NetworkIntegration::Both,
            'wall_url' => 'https://clickwall.net/app/iframe/YOUR_APP_ID/{sub_id}',
        ],
        // Swaarm feed. They sign nothing and publish no firing addresses, so
        // the allowlist starts empty and any-source stays on. The query name
        // is `api_key` — Swagger's `apiKey` is HTTP 403, not an empty catalogue.
        ['driver' => 'skylup', 'name' => 'Skylup', 'website' => 'https://www.skylup.co/', 'logo' => 'skylup.svg'],
        // Visitor Offer API (Bearer). They sign nothing and publish no firing
        // IPs, so the allowlist starts empty and any-source stays on. The
        // postback URL is ours to write — macros they do not list are stripped.
        // Logo: public/img/networks/ogads.png.
        ['driver' => 'ogads', 'name' => 'OGAds', 'website' => 'https://ogads.com', 'logo' => 'ogads.png'],
        // Hosted wall only — there is no catalogue. They sign nothing, so the
        // thirteen MAF addresses below are the whole of the authentication.
        // `hosts_wall` and the iframe template are written once, at creation,
        // because the Ad Unit ID in the URL is an admin's to replace.
        [
            'driver' => 'mychips',
            'name' => 'MyChips',
            'website' => 'https://maf.ad',
            'logo' => 'mychips.svg',
            'hosts_wall' => true,
            'wall_url' => 'https://sdk.mychips.io/content?content_id=YOUR_ADUNIT_ID&user_id={user_id}&click_id={sub_id}&aff_sub1={sub_id}&webview=1',
            'postback_ips' => [
                '168.63.37.145',
                '20.54.96.37',
                '13.70.194.104',
                '34.146.139.91',
                '34.54.234.115',
                '34.54.248.253',
                '34.64.93.62',
                '34.47.93.43',
                '34.84.180.208',
                '48.209.163.104',
                '4.207.193.125',
                '48.209.162.122',
                '34.140.72.20',
            ],
        ],
    ];

    /**
     * Not a partner and not pretending to be one.
     *
     * `offers.network_id` is a required foreign key, so an offer we sell
     * ourselves — typed into the admin form by hand — still needs a network row
     * to belong to. This is that row, and it is named for what it is rather
     * than sitting in the partner list as a fictional supplier called "Direct".
     * It carries the PLAYNDGAIN mark, has no website, and its driver reports
     * `syncsCatalogue() === false`, so no sweep can ever deactivate the offers
     * an admin entered by hand.
     *
     * It is also where the $0.01 test offer lives — docs/03 §7.
     */
    private const HOUSE = [
        'driver' => 'direct',
        'name' => 'PLAYNDGAIN (house)',
        'website' => null,
        'logo' => 'house.svg',
    ];

    /**
     * Re-runnable on a local database, which `updateOrCreate` was not.
     *
     * Descriptive columns are the seeder's to own and are refreshed every
     * run; anything an admin has since typed — credentials, status, the
     * source-IP allowlist, sync cadence — is written once, at creation,
     * and never again. Production never reaches this body.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        foreach (self::NETWORKS as $index => $network) {
            // Listed best-first, so the earlier a partner appears the higher it
            // sorts on a wall where two offers are otherwise equal.
            $this->upsert($network, count(self::NETWORKS) - $index);
        }

        // Last, and deliberately: house inventory is what fills a gap, not what
        // outranks a partner's offer.
        $this->upsert(self::HOUSE, 0);
    }

    /**
     * @param  array{driver: string, name: string, website: ?string, logo: string, postback_ips?: list<string>, hosts_wall?: bool, wall_url?: string, integration?: NetworkIntegration}  $network
     */
    private function upsert(array $network, int $priority): void
    {
        $row = Network::query()->firstOrNew(['driver' => $network['driver']]);

        $row->fill([
            'name' => $network['name'],
            'website' => $network['website'],
            'logo_path' => self::LOGO_PATH.$network['logo'],
            'priority' => $priority,
        ]);

        if (! $row->exists) {
            $hostsWall = $network['hosts_wall'] ?? false;

            $row->fill([
                'status' => NetworkStatus::Disabled,
                'credentials' => null,
                // Seeded where the network publishes a fixed set, left empty
                // otherwise. Empty still rejects if the any-source flag is off;
                // the flag starts on so a partner that will not publish IPs
                // can earn without an admin pasting a list first.
                'postback_ips' => $network['postback_ips'] ?? [],
                'allow_any_postback_ip' => true,
                'sync_interval_minutes' => 60,
                'offers_count' => 0,
                // A seeded wall with no catalogue is embed-only. Everyone else
                // starts as API; an admin who later frames the wall picks Both.
                'integration' => $network['integration'] ?? ($hostsWall ? NetworkIntegration::Embed : NetworkIntegration::Api),
                'hosts_wall' => $hostsWall,
                'wall_url' => $network['wall_url'] ?? null,
            ]);
        }

        $row->save();
    }
}
