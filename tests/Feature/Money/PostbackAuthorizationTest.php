<?php

use App\Enums\ConversionStatus;
use App\Enums\IncomingPostbackResult;
use App\Enums\LedgerEntryType;
use App\Enums\NetworkStatus;
use App\Models\Conversion;
use App\Models\IncomingPostback;
use App\Models\LedgerEntry;
use App\Models\Network;

require_once __DIR__.'/MoneyFixtures.php';

/**
 * The gates in front of the money, tested from the outside.
 *
 * These were not caught by either the driver suite or the conversion suite,
 * because each was individually reasonable: a driver for a network that signs
 * nothing returned true when no key was configured "because the IP allowlist
 * is the backstop", while the ingress admitted any IP when the allowlist was
 * empty "because an empty list means unconfigured". Composed, the two produced
 * an unauthenticated endpoint that writes ledger entries.
 *
 * docs/00-overview.md §8: "Signature verification **and** source IP allowlist
 * per network. Both, not either."
 */
it('refuses a postback to a network that is not active', function (NetworkStatus $status) {
    $click = moneyFixture();
    Network::query()->where('driver', 'fake')->update(['status' => $status]);

    firePostback(postbackDto($click))->assertForbidden();

    expect(Conversion::query()->count())->toBe(0)
        ->and(LedgerEntry::query()->count())->toBe(0)
        ->and(IncomingPostback::query()->sole()->result)->toBe(IncomingPostbackResult::Rejected);
})->with([
    'disabled' => NetworkStatus::Disabled,
    'error' => NetworkStatus::Error,
]);

it('still accepts a chargeback for a network that has since been disabled', function () {
    // Ayet Studios' key expired, the sync failed, the network went off — and
    // 1,452 chargebacks for conversions we had already credited came back 403.
    // A disabled network must not earn; refusing to let it take money back is
    // the publisher keeping a payout the advertiser had already clawed.
    $click = moneyFixture();

    firePostback(postbackDto($click))->assertOk();

    Network::query()->where('driver', 'fake')->update(['status' => NetworkStatus::Disabled]);

    firePostback(postbackDto($click, 'reversed'))->assertOk();

    $conversion = Conversion::query()->sole();

    expect($conversion->status)->toBe(ConversionStatus::Reversed)
        ->and(LedgerEntry::query()->where('type', LedgerEntryType::Chargeback)->count())->toBe(1)
        ->and(IncomingPostback::query()->latest('id')->first()->result)->toBe(IncomingPostbackResult::Accepted);
});

it('refuses a postback when the network has no source IP allowlist', function () {
    $click = moneyFixture();
    Network::query()->where('driver', 'fake')->update(['postback_ips' => []]);

    firePostback(postbackDto($click))->assertForbidden();

    expect(Conversion::query()->count())->toBe(0)
        ->and(LedgerEntry::query()->count())->toBe(0);

    $log = IncomingPostback::query()->sole();

    expect($log->result)->toBe(IncomingPostbackResult::Rejected)
        ->and($log->ip_allowed)->toBeFalse();
});

it('admits any source IP only when an admin opts the network out of the allowlist', function () {
    $click = moneyFixture();
    Network::query()->where('driver', 'fake')->update([
        'postback_ips' => [],
        'allow_any_postback_ip' => true,
    ]);

    firePostback(postbackDto($click))->assertOk();

    expect(Conversion::query()->count())->toBe(1)
        ->and(IncomingPostback::query()->sole()->ip_allowed)->toBeTrue();
});

it('refuses a postback from an address outside the allowlist', function () {
    $click = moneyFixture();
    Network::query()->where('driver', 'fake')->update(['postback_ips' => ['203.0.113.7']]);

    firePostback(postbackDto($click))->assertForbidden();

    expect(Conversion::query()->count())->toBe(0);
});

it('still logs the raw request when it refuses at the network gate', function () {
    $click = moneyFixture();
    Network::query()->where('driver', 'fake')->update(['status' => NetworkStatus::Disabled]);

    firePostback(postbackDto($click), ['transaction_id' => 'NTX-9', 'payout' => '0.13']);

    // A network claiming "we sent you 400 conversions" is settled by this row.
    // A log written only for requests that already passed proves nothing.
    $log = IncomingPostback::query()->sole();

    expect($log->body)->toMatchArray(['transaction_id' => 'NTX-9', 'payout' => '0.13'])
        ->and($log->reject_reason)->toContain('not active');
});

it('accepts once both gates are satisfied', function () {
    $click = moneyFixture();

    firePostback(postbackDto($click))->assertOk();

    expect(Conversion::query()->count())->toBe(1)
        ->and(IncomingPostback::query()->sole()->result)->toBe(IncomingPostbackResult::Accepted);
});
