<?php

use App\Networks\Exceptions\InvalidTenantUrl;
use App\Networks\Support\PostbackSecret;
use App\Networks\Support\TenantUrl;

/**
 * The first hostname this application dials that an admin typed.
 *
 * Every driver before the platform connectors talked to a constant, so nothing
 * in the codebase had ever needed to ask whether a host was safe to call. These
 * are the answers, and each refusal is a way the allowlist would otherwise be
 * walked around.
 */
const SWAARM_SUFFIXES = ['.swaarm-clients.com', '.swaarm.com'];

it('accepts a tenant on a suffix the connector owns', function (string $url, string $normalised) {
    expect(TenantUrl::validate($url, SWAARM_SUFFIXES))->toBe($normalised);
})->with([
    'feed path' => ['https://feed.acme.swaarm-clients.com/feed/v1.2/ads', 'https://feed.acme.swaarm-clients.com/feed/v1.2/ads'],
    'apex' => ['https://swaarm.com/feed/v1.2/ads', 'https://swaarm.com/feed/v1.2/ads'],
    // Normalised rather than echoed: the string that was validated has to be
    // the string that is dialled.
    'upper case host' => ['https://Feed.ACME.swaarm.com/ads', 'https://feed.acme.swaarm.com/ads'],
    'explicit 443' => ['https://feed.acme.swaarm.com:443/ads', 'https://feed.acme.swaarm.com/ads'],
    'trailing dot' => ['https://feed.acme.swaarm.com./ads', 'https://feed.acme.swaarm.com/ads'],
    'surrounding space' => ['  https://feed.acme.swaarm.com/ads  ', 'https://feed.acme.swaarm.com/ads'],
    'query kept' => ['https://feed.acme.swaarm.com/ads?v=2', 'https://feed.acme.swaarm.com/ads?v=2'],
]);

it('refuses a host the connector does not own', function (string $url) {
    expect(fn () => TenantUrl::validate($url, SWAARM_SUFFIXES))->toThrow(InvalidTenantUrl::class)
        ->and(TenantUrl::passes($url, SWAARM_SUFFIXES))->toBeFalse();
})->with([
    'plain http' => ['http://feed.acme.swaarm.com/ads'],
    // The host to a naive suffix check is `swaarm.com`; to every HTTP client
    // it is `evil.test`.
    'userinfo smuggling' => ['https://feed.acme.swaarm.com@evil.test/ads'],
    'password smuggling' => ['https://user:pass@feed.acme.swaarm.com/ads'],
    'other port' => ['https://feed.acme.swaarm.com:8080/ads'],
    // `str_ends_with('notswaarm.com', 'swaarm.com')` is true. The match is on a
    // label boundary for exactly this string.
    'suffix lookalike' => ['https://notswaarm.com/ads'],
    'unrelated host' => ['https://evil.test/ads'],
    'ipv4 literal' => ['https://169.254.169.254/latest/meta-data/'],
    'ipv6 literal' => ['https://[::1]/ads'],
    'localhost' => ['https://localhost/ads'],
    'internal tld' => ['https://feed.swaarm.internal/ads'],
    'mdns' => ['https://printer.local/ads'],
    'reverse dns' => ['https://10.in-addr.arpa/ads'],
    'not a url' => ['definitely not a url'],
    'empty' => [''],
]);

it('names the connector in the refusal so an admin can act on it', function () {
    expect(fn () => TenantUrl::validate('https://evil.test/x', ['.affise.com']))
        ->toThrow(InvalidTenantUrl::class, 'The API URL must be on affise.com.');
});

it('compares a postback secret in constant time and fails closed on a blank one', function () {
    expect(PostbackSecret::matches('s3cr3t', 's3cr3t'))->toBeTrue()
        ->and(PostbackSecret::matches('s3cr3t', 'wrong'))->toBeFalse()
        // A network saved before its secret was pasted must not authenticate
        // every caller on earth, which `'' === ''` would.
        ->and(PostbackSecret::matches('', ''))->toBeFalse()
        ->and(PostbackSecret::matches(null, null))->toBeFalse()
        ->and(PostbackSecret::matches('s3cr3t', null))->toBeFalse()
        ->and(PostbackSecret::matches(null, 's3cr3t'))->toBeFalse();
});

it('accepts a secret from any of the places a connector allows it', function () {
    expect(PostbackSecret::matchesAny('s3cr3t', [null, 's3cr3t', null]))->toBeTrue()
        ->and(PostbackSecret::matchesAny('s3cr3t', [null, 'wrong']))->toBeFalse()
        ->and(PostbackSecret::matchesAny('', [null, '']))->toBeFalse();
});
