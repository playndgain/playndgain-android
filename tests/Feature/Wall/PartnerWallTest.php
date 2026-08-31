<?php

use App\Enums\BannedBy;
use App\Enums\FraudStage;
use App\Fraud\Decision;
use App\Fraud\FraudContext;
use App\Fraud\FraudPipeline;
use App\Fraud\RuleRegistry;
use App\Models\BannedEndUser;
use App\Models\Network;
use App\Models\OfferClick;
use App\Support\AppNetworks;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/WallFixtures.php';

function partnerNetwork(array $attributes = []): Network
{
    return Network::factory()->create([
        'wall_url' => 'https://p.test/w?s={sub_id}&u={user_id}',
        ...$attributes,
    ]);
}

function partnerGet(App\Models\App $app, ?Network $network = null, string $user = 'USER_1', array $extra = []): TestResponse
{
    $query = http_build_query(['appid' => $app->app_id, 'userid' => $user, ...$extra]);
    $path = $network === null ? '/wall/partners' : "/wall/partners/{$network->id}";

    return test()->withHeaders(['User-Agent' => WALL_UA])->get("{$path}?{$query}");
}

it('shows partner cards until the user opens one, then frames it', function () {
    wallGeo('US');
    [$app] = wallFixture();
    $second = partnerNetwork(['priority' => 1, 'name' => 'Second']);
    $first = partnerNetwork(['priority' => 9, 'name' => 'First', 'logo_path' => 'img/networks/ayetstudios.webp']);

    partnerGet($app)->assertOk()
        ->assertSee('wall-launch', false)
        ->assertSee($first->name)
        ->assertSee($second->name)
        ->assertSee(__('wall.partner.kicker'))
        ->assertSee('img/networks/ayetstudios.webp', false)
        ->assertSee("/wall/partners/{$first->id}?", false)
        ->assertSee('open=1', false)
        ->assertDontSee('<iframe', false)
        ->assertDontSee('https://p.test/w', false);

    expect(OfferClick::query()->count())->toBe(0);

    $response = partnerGet($app, extra: ['open' => '1'])->assertOk();

    $click = OfferClick::query()->whereNull('offer_id')->sole();

    expect($click->network_id)->toBe($first->id);

    $response->assertSee("https://p.test/w?s={$click->click_id}&amp;u=USER_1", false)
        ->assertSee('sandbox="allow-scripts allow-same-origin allow-popups allow-forms allow-top-navigation-by-user-activation"', false)
        ->assertSee(__('wall.partner.back'))
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('carries the wall parameters into every partner link', function () {
    wallGeo('US');
    [$app] = wallFixture(['require_signed_wall' => true]);
    $other = partnerNetwork(['priority' => 1]);
    partnerNetwork(['priority' => 9]);

    // The sub-strip links have to survive a signed app, or switching partner
    // is a 403 the publisher cannot debug.
    partnerGet($app, null, 'USER_1', ['sig' => wallSig($app)])
        ->assertOk()
        ->assertSee("/wall/partners/{$other->id}?appid={$app->app_id}&amp;userid=USER_1&amp;sig=".wallSig($app), false);

    partnerGet($app)->assertForbidden();
});

it('frames a partner in our wall even when it is marked to open elsewhere', function () {
    wallGeo('US');
    [$app] = wallFixture();
    $partner = partnerNetwork([
        'wall_new_tab' => true,
        'logo_path' => 'img/networks/ayetstudios.webp',
    ]);

    partnerGet($app)->assertOk()
        ->assertSee('wall-launch', false)
        ->assertSee($partner->name)
        ->assertSee(__('wall.partner.kicker'))
        ->assertSee("/wall/partners/{$partner->id}?", false)
        ->assertDontSee('<iframe', false)
        ->assertDontSee('https://p.test/w', false);

    partnerGet($app, $partner, extra: ['open' => '1'])->assertOk()
        ->assertSee('<iframe', false)
        ->assertSee('https://p.test/w', false);
});

it('names the cause when there is no partner to show', function () {
    wallGeo('US');
    [$app] = wallFixture();

    partnerGet($app)->assertOk()
        ->assertSee(__('wall.partner.empty.title'))
        ->assertSee(__('wall.partner.empty.action'))
        ->assertDontSee('<iframe', false);

    expect(OfferClick::query()->count())->toBe(0);

    partnerGet($app, partnerNetwork(['wall_url' => null]))->assertNotFound();
    partnerGet($app, Network::factory()->disabled()->create(['wall_url' => 'https://p.test/w']))->assertNotFound();
    partnerGet($app, partnerNetwork(['wall_url' => 'http://p.test/w']))->assertNotFound();
});

it('hides a partner the app has turned off', function () {
    wallGeo('US');
    [$app] = wallFixture();
    $partner = partnerNetwork(['name' => 'Framed Co']);

    $enabled = array_values(array_filter(
        AppNetworks::enabledIds($app),
        fn (string $id): bool => (int) $id !== $partner->id,
    ));

    AppNetworks::sync($app, $enabled);

    partnerGet($app)->assertOk()->assertDontSee($partner->name);
    partnerGet($app, $partner)->assertNotFound();
});

it('holds a partner behind the consent gate', function () {
    wallConsentCountries(['DE']);
    wallGeo('DE');
    [$app] = wallFixture();
    $partner = partnerNetwork();

    // Deep-linking a partner must not walk past the gate — an embedded wall
    // sets third-party cookies, which is the processing the gate exists for.
    partnerGet($app, $partner)->assertOk()->assertDontSee('<iframe', false);

    expect(OfferClick::query()->count())->toBe(0);

    // Through the endpoint, not a raw create: `consents.ip` and
    // `user_agent_hash` are NOT NULL, and the POST is the path that fills them.
    test()->withHeaders(['User-Agent' => WALL_UA])
        ->post('/wall/consent', ['appid' => $app->app_id, 'userid' => 'USER_1', 'action' => 'decline']);

    partnerGet($app, $partner)->assertOk()->assertSee(__('wall.notice.declined.title'));

    expect(OfferClick::query()->count())->toBe(0);
});

it('refuses a banned end user before it resolves a partner', function () {
    wallGeo('US');
    [$app] = wallFixture();
    $partner = partnerNetwork();

    // `banned_by` is NOT NULL — see tests/Feature/Fraud/PreClickRulesTest.php
    // for the canonical shape of this row.
    BannedEndUser::query()->create([
        'publisher_id' => $app->publisher_id,
        'app_id' => $app->id,
        'end_user_id' => 'USER_1',
        'banned_by' => BannedBy::Publisher,
        'reason' => 'test',
        'expires_at' => null,
    ]);

    partnerGet($app, $partner)->assertOk()
        ->assertSee(__('wall.notice.banned.title'))
        ->assertDontSee('<iframe', false);

    expect(OfferClick::query()->count())->toBe(0);
});

it('answers with the notice, not a frame, when fraud blocks the open', function () {
    wallGeo('US');
    [$app] = wallFixture();
    $partner = partnerNetwork();

    // The ban gate above refuses at step 8, before a partner is resolved. This
    // is the other refusal: the pre-click pipeline blocking at step 10, which
    // still writes its row.
    app()->instance(FraudPipeline::class, new class(app(RuleRegistry::class)) extends FraudPipeline
    {
        public function evaluate(FraudStage $stage, FraudContext $context): Decision
        {
            return new Decision(true, 100, 'stubbed_block');
        }
    });

    partnerGet($app, $partner)->assertOk()->assertDontSee('<iframe', false);
    expect(OfferClick::query()->count())->toBe(0);

    partnerGet($app, $partner, extra: ['open' => '1'])->assertForbidden()
        ->assertSee(__('wall.notice.partner_blocked.title'))
        ->assertDontSee('<iframe', false);

    expect(OfferClick::query()->whereNull('offer_id')->sole()->blocked)->toBeTrue();
});

it('always shows the offerwall tab', function () {
    wallGeo('US');
    [$app, $network] = wallFixture();
    wallOffer($network, ['countries' => [], 'devices' => []]);

    wallGet($app)->assertOk()
        ->assertSee('/wall/partners?appid='.$app->app_id.'&amp;userid=USER_1', false);
});

it('keeps offers as the default tab', function () {
    wallGeo('US');
    [$app, $network] = wallFixture();
    $offer = wallOffer($network, ['countries' => [], 'devices' => []]);
    partnerNetwork();

    // The strip is navigation around the wall, not a change to it.
    wallGet($app)->assertOk()->assertSee($offer->name)->assertDontSee('<iframe', false);
});
