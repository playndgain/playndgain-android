<?php

namespace App\Networks\Support;

use App\Networks\Exceptions\InvalidTenantUrl;
use Illuminate\Support\Str;

/**
 * The first admin-supplied hostname this application will ever dial.
 *
 * Every driver before the platform connectors talked to a constant. A platform
 * does not have one: each partner runs their own tenant on their own subdomain,
 * so the host arrives in a text box. That turns the catalogue pull into a
 * server-side request to wherever an admin typed, which is the shape of every
 * SSRF there has ever been — the interesting targets are not on the internet,
 * they are `169.254.169.254`, `10.x`, and whatever answers on `localhost`.
 *
 * The answer is an allowlist of suffixes the connector owns, plus the rules
 * that stop the allowlist being walked around:
 *
 *   - **https only.** A plaintext catalogue pull carries the API key.
 *   - **No userinfo.** `https://evil.test@feed.swaarm.com/` has host `evil.test`
 *     to a naive suffix check and to almost no HTTP client.
 *   - **No port but 443.** A partner domain that also answers on 8080 is a
 *     different service, and the port is the cheapest thing to change.
 *   - **A DNS name, never an address.** An IP literal cannot match a suffix,
 *     but saying so explicitly is what makes the refusal readable.
 *   - **No internal TLDs.** `.local`, `.internal`, `.arpa` and bare `localhost`
 *     never resolve to a partner.
 *   - **Suffix owned by the connector.** `.swaarm-clients.com` is Swaarm's;
 *     `notswaarm-clients.com` is not, which is why the match is on a dot
 *     boundary rather than `str_ends_with` alone.
 *
 * Validated twice: once as a Filament rule so an admin is told at the point of
 * typing, and once in the driver at request time, because a `networks` row can
 * be edited by hand or by a console command that never saw the form.
 */
final class TenantUrl
{
    /** Hosts that are never a partner, whatever suffix they claim. */
    private const FORBIDDEN_SUFFIXES = ['.local', '.internal', '.arpa', '.localhost', '.home', '.lan'];

    /**
     * Normalise an admin-supplied API base or feed URL, or say why not.
     *
     * @param  list<string>  $allowedSuffixes  Dot-prefixed, e.g. `.swaarm.com`.
     *
     * @throws InvalidTenantUrl
     */
    public static function validate(?string $url, array $allowedSuffixes): string
    {
        $url = trim((string) $url);

        if ($url === '') {
            throw new InvalidTenantUrl('No API URL is configured for this network.');
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            throw new InvalidTenantUrl('That is not a URL.');
        }

        if (($parts['scheme'] ?? '') !== 'https') {
            throw new InvalidTenantUrl('The API URL must start with https://.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidTenantUrl('The API URL must not carry a username or password.');
        }

        if (isset($parts['port']) && $parts['port'] !== 443) {
            throw new InvalidTenantUrl('The API URL must use the standard https port.');
        }

        $host = strtolower(rtrim($parts['host'], '.'));

        if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            throw new InvalidTenantUrl('The API URL must name a host, not an address.');
        }

        // Bracketed IPv6 survives `parse_url` with its brackets on, so the
        // check above misses it.
        if (str_starts_with($host, '[')) {
            throw new InvalidTenantUrl('The API URL must name a host, not an address.');
        }

        if (Str::endsWith($host, self::FORBIDDEN_SUFFIXES)) {
            throw new InvalidTenantUrl('The API URL must name a public host.');
        }

        if (! self::hostMatches($host, $allowedSuffixes)) {
            throw new InvalidTenantUrl('The API URL must be on '.self::describe($allowedSuffixes).'.');
        }

        // Rebuilt rather than returned as typed: a trailing space, a default
        // port written out, or an upper-case host would all reach the HTTP
        // client otherwise, and the string that was validated has to be the
        // string that is dialled.
        return 'https://'.$host.($parts['path'] ?? '')
            .(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    /**
     * @param  list<string>  $allowedSuffixes
     */
    public static function passes(?string $url, array $allowedSuffixes): bool
    {
        try {
            self::validate($url, $allowedSuffixes);

            return true;
        } catch (InvalidTenantUrl) {
            return false;
        }
    }

    /**
     * A suffix matches on a label boundary or as the whole host. Without the
     * boundary, `.affise.com` would also admit `evilaffise.com`.
     *
     * @param  list<string>  $allowedSuffixes
     */
    private static function hostMatches(string $host, array $allowedSuffixes): bool
    {
        foreach ($allowedSuffixes as $suffix) {
            $suffix = strtolower($suffix);
            $bare = ltrim($suffix, '.');

            if ($host === $bare || str_ends_with($host, '.'.$bare)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $allowedSuffixes
     */
    private static function describe(array $allowedSuffixes): string
    {
        return collect($allowedSuffixes)
            ->map(fn (string $suffix): string => ltrim($suffix, '.'))
            ->implode(' or ');
    }
}
