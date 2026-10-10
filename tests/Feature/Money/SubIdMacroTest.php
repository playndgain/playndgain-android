<?php

use App\Enums\OutgoingPostbackKind;
use App\Models\Conversion;
use App\Services\Money\MacroResolver;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/MoneyFixtures.php';

it('accepts a callback url that uses the sub id macros', function () {
    expect(MacroResolver::isHttpsTemplate('https://pub.test/cb?u={user_id}&s={sub1}&t={sub5}'))->toBeTrue()
        ->and(MacroResolver::isHttpsTemplate('http://pub.test/cb?s={sub1}'))->toBeFalse();
});

it('echoes sub ids from the click and blanks the missing ones', function () {
    Queue::fake();

    $click = moneyFixture(click: ['sub1' => 'abc', 'sub2' => null, 'sub5' => 'x.y']);
    firePostback(postbackDto($click))->assertOk();

    $conversion = Conversion::query()->sole();

    expect(MacroResolver::resolve('https://pub.test/cb?a={sub1}&b={sub2}&e={sub5}', $conversion, OutgoingPostbackKind::Credit))
        ->toBe('https://pub.test/cb?a=abc&b=&e=x.y');
});

it('resolves sub id macros to blank for a conversion with no click', function () {
    $conversion = Conversion::factory()->make(['offer_click_id' => null]);

    expect(MacroResolver::resolve('https://pub.test/cb?a={sub1}', $conversion, OutgoingPostbackKind::Credit))
        ->toBe('https://pub.test/cb?a=');
});
