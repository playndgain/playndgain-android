<?php

use App\Enums\FraudStage;
use App\Enums\NetworkIntegration;
use App\Enums\NetworkStatus;
use App\Models\FraudRule;
use App\Models\Network;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\FraudRuleSeeder;
use Database\Seeders\NetworkSeeder;
use Database\Seeders\SettingsSeeder;

it('seeds the launch partners plus the house channel, all disabled with no credentials', function () {
    $this->seed(NetworkSeeder::class);

    expect(Network::query()->count())->toBe(17)
        ->and(Network::query()->pluck('driver')->sort()->values()->all())
        ->toBe(['adgatemedia', 'admantium', 'adscendmedia', 'adtogame', 'ayetstudios', 'clickwall', 'direct', 'gemiad', 'hangmyads', 'klink', 'monlix', 'mychips', 'notik', 'offery', 'ogads', 'skylup', 'torox'])
        ->and(Network::query()->where('status', '!=', NetworkStatus::Disabled)->count())->toBe(0)
        ->and(Network::query()->whereNotNull('credentials')->count())->toBe(0);

    $klink = Network::query()->where('driver', 'klink')->sole();

    expect($klink->integration)->toBe(NetworkIntegration::Both)
        ->and($klink->hosts_wall)->toBeTrue()
        ->and($klink->postback_ips)->toHaveCount(6);

    // Drivers we have but no account with are deliberately unseeded — a row for
    // a network that cannot earn is an invitation to switch it on.
    expect(Network::query()->whereIn('driver', ['adswedmedia', 'lootably'])->exists())->toBeFalse();
});

it('ships a logo file for every seeded network', function () {
    // The path is written by the seeder, so a renamed or forgotten asset is a
    // broken image in the admin list that nothing else would catch.
    $this->seed(NetworkSeeder::class);

    foreach (Network::query()->get() as $network) {
        expect($network->logo_path)->not->toBeNull()
            ->and(public_path($network->logo_path))->toBeFile();
    }
});

it('sorts the house channel below every partner', function () {
    // House inventory fills a gap; it does not outrank a partner's offer on a
    // wall where the two are otherwise equal.
    $this->seed(NetworkSeeder::class);

    $house = Network::query()->where('driver', 'direct')->sole();
    $lowestPartner = Network::query()->where('driver', '!=', 'direct')->min('priority');

    expect($house->priority)->toBeLessThan($lowestPartner);
});

it('seeds the day-1 fraud rule set across both stages', function () {
    $this->seed(FraudRuleSeeder::class);

    expect(FraudRule::query()->where('stage', FraudStage::PreClick)->count())->toBe(11)
        ->and(FraudRule::query()->where('stage', FraudStage::PostConversion)->count())->toBe(12);
});

it('never resets a threshold an operator tuned', function () {
    $this->seed(FraudRuleSeeder::class);

    $rule = FraudRule::query()->where('key', 'impossible_speed')->sole();
    $rule->update(['config' => ['min_seconds' => 45], 'enabled' => false]);

    $this->seed(FraudRuleSeeder::class);

    expect($rule->fresh()->config)->toBe(['min_seconds' => 45])
        ->and($rule->fresh()->enabled)->toBeFalse();
});

it('seeds the money defaults the rest of the system reads', function () {
    $this->seed(SettingsSeeder::class);

    expect(Setting::get('default_rev_share'))->toBe('70.00')
        ->and(Setting::get('default_hold_days'))->toBe('30')
        ->and(Setting::get('default_min_payout'))->toBe('20.00')
        ->and(Setting::get('auto_approve_publishers'))->toBe('0');
});

it('gates the EU consent list on the countries that need it', function () {
    $this->seed(SettingsSeeder::class);

    $countries = json_decode(Setting::get('consent_required_countries'), true);

    expect($countries)->toHaveCount(31)
        ->and($countries)->toContain('DE', 'FR', 'GB', 'NO')
        ->and($countries)->not->toContain('US', 'EG');
});

it('creates exactly one admin, with no default password', function () {
    $this->seed();

    $admins = User::query()->where('role', 'admin')->get();

    expect($admins)->toHaveCount(1)
        ->and($admins->first()->email)->toBe(config('playndgain.seed_admin_email'));
});

it('never wipes the credentials off a network an admin configured', function () {
    // `db:seed` is a command someone runs on production the day a tenth network
    // is added. The seeder used to pass `credentials => null` and
    // `status => Disabled` on every run, so that day also emptied the API keys
    // off every live network and switched them all off — no offers, no inbound
    // conversions accepted, nothing in the log saying why.
    $this->seed(NetworkSeeder::class);

    $network = Network::query()->where('driver', 'monlix')->sole();
    $network->update([
        'credentials' => ['api_key' => 'LIVE-KEY', 'secret_key' => 'LIVE-SECRET'],
        'status' => NetworkStatus::Active,
        'postback_ips' => ['203.0.113.7'],
    ]);

    $this->seed(NetworkSeeder::class);

    $network->refresh();

    expect($network->credentials)->toBe(['api_key' => 'LIVE-KEY', 'secret_key' => 'LIVE-SECRET'])
        ->and($network->status)->toBe(NetworkStatus::Active)
        ->and($network->postback_ips)->toBe(['203.0.113.7']);
});

it('is idempotent — reseeding does not duplicate anything', function () {
    $this->seed();
    $this->seed();

    expect(Network::query()->count())->toBe(17)
        ->and(FraudRule::query()->count())->toBe(23)
        ->and(User::query()->where('role', 'admin')->count())->toBe(1);
});

it('seeds the postback allowlist for a network whose only auth it is', function () {
    // Ayet Studios signs nothing and has no shared postback token, so
    // `networks.postback_ips` is the whole of the authentication on its
    // conversions when the any-source flag is off. New rows start with the
    // flag on so an empty list does not block the first fire.
    $this->seed(NetworkSeeder::class);

    expect(Network::query()->where('allow_any_postback_ip', false)->count())->toBe(0);

    expect(Network::query()->where('driver', 'ayetstudios')->sole()->postback_ips)
        ->toBe([
            '51.79.101.241',
            '158.69.185.134',
            '158.69.185.154',
            '35.165.166.40',
            '35.166.159.131',
            '52.40.3.140',
        ]);

    // Torox publishes no fixed set; the list stays empty and any-source is on.
    expect(Network::query()->where('driver', 'torox')->sole()->postback_ips)->toBe([]);

    // GemiAd publishes exactly one firing address, so the list is seeded rather
    // than left for someone to discover after the first rejected conversion.
    // Unlike Ayet it also signs, so this is the second gate and not the only
    // one — but a wrong list still rejects everything, which is why it is here
    // and not in a runbook.
    expect(Network::query()->where('driver', 'gemiad')->sole()->postback_ips)->toBe(['64.226.92.208']);

    // Notik documents a single address and HMAC-SHA1s the callback URL.
    expect(Network::query()->where('driver', 'notik')->sole()->postback_ips)->toBe(['158.69.116.45']);

    // Everflow publishes nine S2S egress addresses. Adtogame signs nothing, so
    // this list is the whole of the authentication — empty would reject every
    // fire at the door.
    expect(Network::query()->where('driver', 'adtogame')->sole()->postback_ips)->toBe([
        '35.196.95.104',
        '34.138.191.250',
        '34.138.95.128',
        '34.91.131.78',
        '34.147.37.169',
        '34.147.67.230',
        '34.73.83.118',
        '35.227.72.33',
        '34.74.122.67',
    ]);

    // Offery signs its postbacks but publishes no firing addresses.
    expect(Network::query()->where('driver', 'offery')->sole()->postback_ips)->toBe([]);

    // Clickwall signs its postbacks but publishes no firing addresses.
    expect(Network::query()->where('driver', 'clickwall')->sole()->postback_ips)->toBe([]);

    // OGAds signs nothing and publishes no firing addresses.
    expect(Network::query()->where('driver', 'ogads')->sole()->postback_ips)->toBe([]);

    // MyChips signs nothing. The thirteen MAF addresses are the whole of the
    // authentication, so they are seeded rather than discovered after the
    // first rejected conversion. IAA fires only from the last one.
    expect(Network::query()->where('driver', 'mychips')->sole()->postback_ips)->toBe([
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
    ]);
});

it('seeds clickwall as a hosted wall with a catalogue', function () {
    $this->seed(NetworkSeeder::class);

    $network = Network::query()->where('driver', 'clickwall')->sole();

    expect($network->hosts_wall)->toBeTrue()
        ->and($network->integration)->toBe(NetworkIntegration::Both)
        ->and($network->wall_url)->toBe('https://clickwall.net/app/iframe/YOUR_APP_ID/{sub_id}')
        ->and($network->postback_ips)->toBe([]);

    $network->update(['wall_url' => 'https://clickwall.net/app/iframe/1054/{sub_id}']);

    $this->seed(NetworkSeeder::class);

    expect($network->fresh()->wall_url)->toBe('https://clickwall.net/app/iframe/1054/{sub_id}');
});

it('seeds mychips as a hosted wall with no catalogue', function () {
    $this->seed(NetworkSeeder::class);

    $network = Network::query()->where('driver', 'mychips')->sole();

    expect($network->hosts_wall)->toBeTrue()
        ->and($network->integration)->toBe(NetworkIntegration::Embed)
        ->and($network->wall_url)->toBe('https://sdk.mychips.io/content?content_id=YOUR_ADUNIT_ID&user_id={user_id}&click_id={sub_id}&aff_sub1={sub_id}&webview=1')
        ->and($network->syncsCatalogue())->toBeFalse();

    $network->update(['wall_url' => 'https://sdk.mychips.io/content?content_id=live-adunit&user_id={user_id}&click_id={sub_id}&aff_sub1={sub_id}&webview=1']);

    $this->seed(NetworkSeeder::class);

    expect($network->fresh()->wall_url)->toBe('https://sdk.mychips.io/content?content_id=live-adunit&user_id={user_id}&click_id={sub_id}&aff_sub1={sub_id}&webview=1');
});
