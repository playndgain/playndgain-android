<?php

use App\Enums\ConversionStatus;
use App\Enums\IncomingPostbackResult;
use App\Enums\LedgerEntryType;
use App\Enums\OutgoingPostbackKind;
use App\Jobs\DispatchPublisherPostback;
use App\Models\App;
use App\Models\Conversion;
use App\Models\IncomingPostback;
use App\Models\LedgerEntry;
use App\Models\Network;
use App\Models\Offer;
use App\Models\Publisher;
use App\Networks\Drivers\FakeNetworkDriver;
use App\Networks\Drivers\SwaarmDriver;
use App\Networks\NetworkDriverManager;
use Database\Factories\OfferClickFactory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PlatformTestDriver;

/**
 * Callback identity. The route used to name the driver class; it now names the
 * row, and the two have to keep agreeing for every URL already pasted into a
 * partner dashboard.
 */
beforeEach(function () {
    app(NetworkDriverManager::class)->register(FakeNetworkDriver::key(), FakeNetworkDriver::class);
    app(NetworkDriverManager::class)->register(PlatformTestDriver::key(), PlatformTestDriver::class);
    FakeNetworkDriver::reset();
});

it('still resolves a network whose key is its driver, as the backfill left it', function () {
    $network = Network::factory()->create(['driver' => 'fake']);

    expect($network->postback_key)->toBe('fake');

    $this->post('/postback/fake')->assertUnprocessable();

    expect(IncomingPostback::query()->sole()->network_id)->toBe($network->id);
});

it('resolves a network whose key has nothing to do with its driver', function () {
    $network = Network::factory()->platform('platform-test')->create();

    expect($network->postback_key)->not->toBe('platform-test')
        ->and($network->account_scope)->toBe($network->postback_key);

    $this->post('/postback/'.$network->postback_key)->assertUnprocessable();

    expect(IncomingPostback::query()->sole()->network_id)->toBe($network->id);
});

it('lets two accounts share one platform connector and keeps their callbacks apart', function () {
    $first = Network::factory()->platform('platform-test')->create();
    $second = Network::factory()->platform('platform-test')->create();

    expect($second->postback_key)->not->toBe($first->postback_key);

    $this->post('/postback/'.$second->postback_key)->assertUnprocessable();

    // The fire has to land on the account it was addressed to. Resolving by
    // driver would have found whichever row came back first.
    expect(IncomingPostback::query()->sole()->network_id)->toBe($second->id);
});

it('refuses a second row on a connector that holds one account', function () {
    Network::factory()->create(['driver' => 'fake']);

    // The application rule lives on the create form; this is the database
    // guarantee underneath it, which is what keeps a seeder or a console
    // command from making the same mistake.
    expect(fn () => Network::factory()->create(['driver' => 'fake', 'postback_key' => 'fake-two']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('logs an unknown key as a 404 that still records what arrived', function () {
    Network::factory()->create(['driver' => 'fake']);

    $this->post('/postback/nobody-here', ['tx' => 'abc'])->assertNotFound();

    $log = IncomingPostback::query()->sole();

    expect($log->postback_key)->toBe('nobody-here')
        ->and($log->driver)->toBe('nobody-here')
        ->and($log->network_id)->toBeNull()
        ->and($log->result)->toBe(IncomingPostbackResult::Error)
        ->and($log->reject_reason)->toBe('unknown postback key')
        ->and($log->body)->toBe(['tx' => 'abc']);
});

it('records the resolved driver on the log, not the route segment', function () {
    $network = Network::factory()->platform('platform-test')->create();

    $this->post('/postback/'.$network->postback_key)->assertUnprocessable();

    $log = IncomingPostback::query()->sole();

    // The replay command filters on `driver` and the log groups by it, so it
    // has to keep naming the class once there is a class to name.
    expect($log->driver)->toBe('platform-test')
        ->and($log->postback_key)->toBe($network->postback_key);
});

it('renders a postback url per account rather than per driver', function () {
    $first = Network::factory()->platform('platform-test')->create();
    $second = Network::factory()->platform('platform-test')->create();

    expect($first->postbackUrl())->toStartWith(url('/postback/'.$first->postback_key))
        ->and($second->postbackUrl())->toStartWith(url('/postback/'.$second->postback_key))
        ->and($second->postbackUrl())->not->toBe($first->postbackUrl());
});

it('has no postback url for a driver that is not registered', function () {
    $network = Network::factory()->create(['driver' => 'gone-away']);

    expect($network->postbackUrl())->toBeNull()
        ->and($network->connector())->toBeNull()
        // An unregistered driver is treated as single-account: the strict
        // direction, so a typo cannot quietly allow duplicates.
        ->and($network->account_scope)->toBe('');
});

it('lands a real swaarm postback on the tenant it was addressed to', function () {
    app(NetworkDriverManager::class)->register(SwaarmDriver::key(), SwaarmDriver::class);

    // Two partners, each on their own Swaarm tenant, each with their own secret.
    // Before this the route named the driver class and there was no way to tell
    // one partner's fires from the other's.
    $acme = Network::factory()->platform('swaarm')->create([
        'name' => 'Acme tenant',
        'credentials' => [
            'feed_url' => 'https://feed.acme.swaarm-clients.com/feed/v1.2/ads',
            'api_key' => 'acme-key',
            'postback_secret' => 'acme-secret',
        ],
    ]);

    $globex = Network::factory()->platform('swaarm')->create([
        'name' => 'Globex tenant',
        'credentials' => [
            'feed_url' => 'https://feed.globex.swaarm-clients.com/feed/v1.2/ads',
            'api_key' => 'globex-key',
            'postback_secret' => 'globex-secret',
        ],
    ]);

    $fire = fn (Network $network, string $secret) => $this->get('/postback/'.$network->postback_key.'?'.http_build_query([
        'click_id' => '9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f',
        'txid' => 'SWX-1',
        'payout' => '1.70',
        'status' => 'Approved',
        'offer_id' => '1001',
        'secret' => $secret,
    ]));

    // No click matches, so the money path parks it — which is the far side of
    // the two gates this test is about.
    $fire($globex, 'globex-secret')->assertOk();

    $log = IncomingPostback::query()->sole();

    expect($log->network_id)->toBe($globex->id)
        ->and($log->driver)->toBe('swaarm')
        ->and($log->signature_valid)->toBeTrue()
        ->and($log->result)->toBe(IncomingPostbackResult::UnknownClick)
        // The secret is the partner's and the log is read across publishers.
        ->and($log->query['secret'])->toBe('REDACTED');

    // The other tenant's secret on this tenant's URL is refused.
    $fire($acme, 'globex-secret')->assertForbidden();

    expect(IncomingPostback::query()->orderByDesc('id')->first()->signature_valid)->toBeFalse();
});

it('takes the money back when a real swaarm tenant declines a conversion it confirmed', function () {
    Queue::fake();
    app(NetworkDriverManager::class)->register(SwaarmDriver::key(), SwaarmDriver::class);

    $network = Network::factory()->platform('swaarm')->create([
        'name' => 'Acme tenant',
        'credentials' => [
            'feed_url' => 'https://feed.acme.swaarm-clients.com/feed/v1.2/ads',
            'api_key' => 'acme-key',
            'postback_secret' => 'acme-secret',
        ],
    ]);

    $owner = Publisher::factory()->create();
    $placement = App::factory()->create(['publisher_id' => $owner->id, 'postback_url' => 'https://pub.test/cb']);
    $offer = Offer::factory()->create(['network_id' => $network->id]);
    $click = OfferClickFactory::new()->create([
        'app_id' => $placement->id,
        'publisher_id' => $owner->id,
        'offer_id' => $offer->id,
        'network_id' => $network->id,
    ]);

    $fire = fn (string $state) => $this->get('/postback/'.$network->postback_key.'?'.http_build_query([
        'click_id' => $click->click_id,
        'txid' => 'SWX-42',
        'payout' => '1.70',
        'status' => $state,
        'offer_id' => $offer->external_id,
        'secret' => 'acme-secret',
    ]));

    $fire('Approved')->assertOk();
    $fire('Rejected')->assertOk();

    $conversion = Conversion::query()->sole();

    // The driver reported the word and the matrix decided what it costs: this
    // connector declares `rejectedAfterCreditReverses`, so a decline after a
    // confirmation is a chargeback — once, not once per fire.
    expect($conversion->status)->toBe(ConversionStatus::Reversed)
        // Audit-only, and it records what the tenant said rather than what we
        // did about it.
        ->and($conversion->upstream_status)->toBe(ConversionStatus::Rejected)
        ->and(LedgerEntry::query()->where('type', LedgerEntryType::Chargeback)->count())->toBe(1)
        ->and(LedgerEntry::query()->where('type', LedgerEntryType::Earning)->count())->toBe(1);

    Queue::assertPushed(
        DispatchPublisherPostback::class,
        fn (DispatchPublisherPostback $job): bool => $job->kind === OutgoingPostbackKind::Reversal,
    );
});
