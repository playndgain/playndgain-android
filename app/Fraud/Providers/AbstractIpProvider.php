<?php

namespace App\Fraud\Providers;

use App\Fraud\IpVerdict;
use App\Fraud\Providers\Contracts\IpProviderDriver;
use App\Models\IpProvider;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Everything both adapters do identically: spend the timeout, count the
 * request, trip the breaker, and never let a vendor's bad day reach a caller.
 *
 * A subclass supplies two things — where to send the request, and how to read
 * the answer. Everything a failure implies is handled here once, because a
 * second adapter that forgets to record a failure is a breaker that never
 * trips for that vendor.
 */
abstract class AbstractIpProvider implements IpProviderDriver
{
    /** @return array{string, array<string, mixed>} URL and query. */
    abstract protected function request(string $ip, IpProvider $config): array;

    /** @param array<string, mixed> $body */
    abstract protected function parse(string $ip, array $body): ?IpVerdict;

    /**
     * A body that decoded but whose vendor status rejected the request — an
     * expired key, a spent quota — reads differently to the operator on
     * Task 8's admin page than a body that would not decode at all. A
     * subclass overrides this to name the vendor's own reason; the default
     * is "no reason offered", which `read()` reports as unreadable.
     *
     * @param  array<string, mixed>  $body
     */
    protected function rejectionReason(array $body): ?string
    {
        return null;
    }

    public function lookup(string $ip, IpProvider $config): ?IpVerdict
    {
        [$url, $query] = $this->request($ip, $config);

        try {
            // No retry. A retry doubles the wait a wall render is already
            // holding for, and the breaker is the right answer to a provider
            // that needs one.
            $response = Http::timeout($config->timeout_ms / 1000)
                ->acceptJson()
                ->get($url, $query);
        } catch (Throwable $e) {
            // A timeout, a DNS failure, a refused connection. All the same
            // thing to a caller: no answer.
            $config->recordFailure(class_basename($e).': '.$e->getMessage());

            return null;
        }

        [$verdict, $error] = $this->read($ip, $response);

        if ($verdict === null) {
            $config->recordFailure($error);

            return null;
        }

        $config->recordSuccess();

        return $verdict;
    }

    /** @return array{0: ?IpVerdict, 1: string} the verdict, or null and why not. */
    private function read(string $ip, Response $response): array
    {
        if (! $response->successful()) {
            return [null, "HTTP {$response->status()}"];
        }

        try {
            $body = $response->json();
        } catch (Throwable) {
            return [null, 'unreadable response body'];
        }

        if (! is_array($body)) {
            return [null, 'unreadable response body'];
        }

        $verdict = $this->parse($ip, $body);

        return $verdict !== null
            ? [$verdict, '']
            : [null, $this->rejectionReason($body) ?? 'unreadable response body'];
    }

    /**
     * `IpVerdict::$country` is documented ISO-2 uppercase, and both vendors
     * can put something else in the field — proxycheck a longer string,
     * ipqualityscore its literal `N/A`. One copy of the rule rather than one
     * per adapter, for the reason `ProxyType`'s own docblock gives: a fraud
     * input normalised two slightly different ways is a rule that fires on
     * one path and not the other.
     */
    protected static function iso(mixed $value): ?string
    {
        return is_string($value) && strlen($value) === 2 ? strtoupper($value) : null;
    }

    /**
     * Vendor scores are only guaranteed 0-100 by `IpVerdict`'s own contract,
     * not by the vendor sending one — a stray negative or an out-of-range
     * value would otherwise pass straight through to `IpRiskScoreRule`'s
     * threshold comparison.
     */
    protected static function riskScore(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, min(100, (int) $value)) : null;
    }
}
