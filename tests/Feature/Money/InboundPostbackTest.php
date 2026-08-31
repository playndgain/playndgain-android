<?php

use App\Enums\ConversionStatus;
use App\Enums\IncomingPostbackResult;
use App\Jobs\DispatchPublisherPostback;
use App\Models\Conversion;
use App\Models\IncomingPostback;
use App\Models\LedgerEntry;
use App\Networks\Drivers\FakeNetworkDriver;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/MoneyFixtures.php';

it('credits a conversion, writes one ledger entry and marks the click converted', function () {
    Queue::fake();

    $click = moneyFixture();

    firePostback(postbackDto($click))->assertOk();

    $conversion = Conversion::query()->sole();

    expect($conversion->status)->toBe(ConversionStatus::Approved)
        ->and($conversion->network_txid)->toBe('NTX-100')
        ->and($conversion->offer_click_id)->toBe($click->id)
        ->and($conversion->trans_id)->toStartWith('OFFER'.$click->offer->external_id.'-')
        ->and(LedgerEntry::query()->count())->toBe(1)
        ->and($click->fresh()->converted_at)->not->toBeNull()
        ->and(IncomingPostback::query()->sole()->result)->toBe(IncomingPostbackResult::Accepted)
        ->and(IncomingPostback::query()->sole()->conversion_id)->toBe($conversion->id);
});

it('logs the raw request before it can resolve the driver', function () {
    $this->post('/postback/nobody-registered-this', ['tx' => 'abc'])
        ->assertNotFound();

    $log = IncomingPostback::query()->sole();

    expect($log->driver)->toBe('nobody-registered-this')
        ->and($log->result)->toBe(IncomingPostbackResult::Error)
        ->and($log->network_id)->toBeNull()
        ->and($log->body)->toBe(['tx' => 'abc']);
});

it('logs the raw request when the source ip is not on the allowlist', function () {
    $click = moneyFixture();
    $click->network->update(['postback_ips' => ['198.51.100.7']]);

    firePostback(postbackDto($click))->assertForbidden();

    $log = IncomingPostback::query()->sole();

    expect($log->result)->toBe(IncomingPostbackResult::Rejected)
        ->and($log->ip_allowed)->toBeFalse()
        ->and($log->network_id)->toBe($click->network_id)
        ->and(Conversion::query()->count())->toBe(0);
});

it('logs the raw request when the signature does not verify', function () {
    $click = moneyFixture();
    FakeNetworkDriver::shouldVerifySignature(false);

    firePostback(postbackDto($click))->assertForbidden();

    $log = IncomingPostback::query()->sole();

    expect($log->result)->toBe(IncomingPostbackResult::Rejected)
        ->and($log->signature_valid)->toBeFalse()
        ->and(Conversion::query()->count())->toBe(0);
});

it('rejects a postback it cannot parse without touching money', function () {
    moneyFixture();

    firePostback(null)->assertUnprocessable();

    expect(IncomingPostback::query()->sole()->result)->toBe(IncomingPostbackResult::Error)
        ->and(Conversion::query()->count())->toBe(0)
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('strips credentials out of the stored request', function () {
    moneyFixture();

    FakeNetworkDriver::shouldParsePostback(null);

    $this->withHeaders(['Authorization' => 'Bearer hunter2', 'X-Trace' => 'keep-me'])
        ->post('/postback/fake', ['secret' => 'sh', 'key' => 'k', 'password' => 'p', 'tx' => 'abc']);

    $log = IncomingPostback::query()->sole();

    expect($log->body)->toBe(['tx' => 'abc'])
        ->and(array_keys($log->headers ?? []))->not->toContain('authorization')
        ->and(array_keys($log->headers ?? []))->toContain('x-trace');
});

it('parks a postback whose click matches nothing and moves no money', function () {
    $click = moneyFixture();

    firePostback(postbackDto($click, clickId: (string) Str::uuid()))->assertOk();

    $log = IncomingPostback::query()->sole();

    expect($log->result)->toBe(IncomingPostbackResult::UnknownClick)
        ->and($log->reject_reason)->not->toBeNull()
        ->and(Conversion::query()->count())->toBe(0)
        ->and(LedgerEntry::query()->count())->toBe(0)
        ->and($click->publisher->fresh()->balance)->toBe('0.000000');
});

it('records a pending conversion without writing to the ledger', function () {
    Queue::fake();

    $click = moneyFixture(app: ['postback_url' => 'https://pub.test/cb?tx={trans_id}']);

    firePostback(postbackDto($click, status: 'pending'))->assertOk();

    expect(Conversion::query()->sole()->status)->toBe(ConversionStatus::Pending)
        ->and(LedgerEntry::query()->count())->toBe(0)
        ->and($click->publisher->fresh()->balance)->toBe('0.000000');

    Queue::assertNothingPushed();
});

it('records a network-rejected conversion without writing to the ledger', function () {
    Queue::fake();

    $click = moneyFixture(app: ['postback_url' => 'https://pub.test/cb?tx={trans_id}']);

    firePostback(postbackDto($click, status: 'rejected'))->assertOk();

    $conversion = Conversion::query()->sole();

    expect($conversion->status)->toBe(ConversionStatus::Rejected)
        ->and($conversion->rejected_reason)->toBe('network_rejected')
        ->and(LedgerEntry::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});

it('writes no ledger entry for a test-mode click but still notifies the publisher', function () {
    Queue::fake();

    $click = moneyFixture(
        app: ['postback_url' => 'https://pub.test/cb?tx={trans_id}'],
        click: ['is_test' => true],
    );

    firePostback(postbackDto($click))->assertOk();

    expect(Conversion::query()->sole()->is_test)->toBeTrue()
        ->and(LedgerEntry::query()->count())->toBe(0)
        ->and($click->publisher->fresh()->balance)->toBe('0.000000');

    Queue::assertPushed(DispatchPublisherPostback::class);
});
