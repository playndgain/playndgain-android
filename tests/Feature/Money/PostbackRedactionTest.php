<?php

use App\Models\IncomingPostback;
use App\Networks\Drivers\FakeNetworkDriver;
use App\Services\Money\Redactor;
use App\Services\Money\UrlRedactor;

require_once __DIR__.'/MoneyFixtures.php';

/**
 * The postback log is read by admins across every publisher and, for a
 * publisher's own rows, by the publisher. A partner credential surviving in it
 * is a leak between accounts.
 */
beforeEach(function () {
    moneyFixture();
    FakeNetworkDriver::shouldParsePostback(null);
});

it('masks every name that reads like a credential, wherever it arrives', function () {
    $this->withHeaders([
        'X-Ayetstudios-Security-Hash' => 'abc123',
        'X-Postback-Secret' => 'shhh',
        'X-Trace' => 'keep-me',
    ])->post('/postback/fake?apikey=live-key&secret=shhh&signature=deadbeef&click_id=CLK-1', [
        'api_key' => 'live-key',
        'token' => 'tok',
        'payout' => '0.13',
    ]);

    $log = IncomingPostback::query()->sole();

    expect($log->query)->toEqual([
        'apikey' => 'REDACTED',
        'secret' => 'REDACTED',
        'signature' => 'REDACTED',
        'click_id' => 'CLK-1',
    ])
        ->and($log->body['api_key'])->toBe('REDACTED')
        ->and($log->body['token'])->toBe('REDACTED')
        // Headers arrive as lists, and the mask keeps the shape so the row
        // still reads like the request that arrived.
        ->and($log->headers['x-ayetstudios-security-hash'])->toBe(['REDACTED'])
        ->and($log->headers['x-postback-secret'])->toBe(['REDACTED'])
        ->and($log->headers['x-trace'])->toBe(['keep-me']);
});

it('leaves every field a driver actually parses alone', function () {
    // Replay and the admin's "match to click" action both re-parse the stored
    // query and body. Anything a `parsePostback()` reads has to survive the
    // mask, or a chargeback replayed from the log matches nothing.
    $this->post('/postback/fake?'.http_build_query([
        'click_id' => 'CLK-1',
        'transaction_id' => 'NTX-1',
        'txid' => 'NTX-1',
        'payout' => '0.13',
        'status' => 'credited',
        'offer_id' => '99',
        'offer_name' => 'Some Offer',
        'user_id' => 'U-1',
        'aff_sub1' => 'CLK-1',
        'subid' => 'CLK-1',
        'ip' => '127.0.0.1',
        'goalname' => 'install',
    ]));

    expect(IncomingPostback::query()->sole()->query)->not->toContain('REDACTED');
});

it('applies the same rule to an outbound url as to a stored payload', function () {
    expect(UrlRedactor::redact('https://p.test/pb?secret=abc&click_id=CLK-1&hash=zz&password=p'))
        ->toBe('https://p.test/pb?secret=REDACTED&click_id=CLK-1&hash=REDACTED&password=REDACTED')
        ->and(Redactor::isSensitive('PASSWORD'))->toBeTrue()
        ->and(Redactor::isSensitive('secretKey'))->toBeTrue()
        ->and(Redactor::isSensitive('sig'))->toBeTrue()
        // `keyword` contains `key` and is masked on purpose: the rule is a name
        // rule, and erring the other way leaks a real credential.
        ->and(Redactor::isSensitive('keyword'))->toBeTrue()
        ->and(Redactor::isSensitive('click_id'))->toBeFalse()
        ->and(Redactor::isSensitive('offer_name'))->toBeFalse();
});

it('masks a nested value without flattening the structure', function () {
    expect(Redactor::keys(['outer' => ['api_key' => 'x', 'id' => 7]]))
        ->toBe(['outer' => ['api_key' => 'REDACTED', 'id' => 7]]);
});
