<?php

namespace App\Http\Controllers;

use App\Enums\IncomingPostbackResult;
use App\Enums\NetworkStatus;
use App\Models\IncomingPostback;
use App\Models\Network;
use App\Networks\Exceptions\UnknownDriverException;
use App\Networks\NetworkDriverManager;
use App\Services\Money\ConversionProcessor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;

/**
 * Inbound network postbacks — docs/04-conversions-postbacks.md §1.
 *
 *   1. Log the raw request to incoming_postbacks — FIRST, before anything can fail
 *   2. Resolve the network by driver key            → 404, result=error
 *   3. Source IP against networks.postback_ips      → 403, result=rejected
 *      (`allow_any_postback_ip` skips this; empty list does not)
 *   4. driver->verifyPostback()                     → 403, result=rejected
 *   5. driver->parsePostback() → PostbackDTO        → 422, result=error
 *   6. networks.status, credits only                → 403, result=rejected
 *   7. Branch on status: credited|reversed|rejected|pending
 *   8. Return 200
 */
class PostbackController extends Controller
{
    /** Written to `reject_reason`; `postbacks:replay-chargebacks` reads it back. */
    public const INACTIVE_REASON = 'network is not active';

    /** Networks put credentials in the payload; none of it belongs in a log row. */
    private const REDACTED_INPUT = ['password', 'secret', 'key'];

    private const REDACTED_HEADERS = ['authorization', 'cookie'];

    public function __construct(
        private readonly NetworkDriverManager $drivers,
        private readonly ConversionProcessor $processor,
    ) {}

    public function __invoke(Request $request, string $driver): Response
    {
        // Step 1 is first for a reason. When a network says "we sent you 400
        // conversions yesterday", this row is the only thing that can confirm
        // or refute it — and a log written after validation only records the
        // requests that were already fine.
        $log = IncomingPostback::create([
            'driver' => $driver,
            'method' => $request->method(),
            'query' => $request->query(),
            'body' => $request->except(self::REDACTED_INPUT),
            'headers' => Arr::except($request->headers->all(), self::REDACTED_HEADERS),
            'ip' => (string) $request->ip(),
            'result' => IncomingPostbackResult::Error,
            'created_at' => now(),
        ]);

        $network = Network::query()->where('driver', $driver)->first();

        if ($network === null) {
            return $this->stop($log, IncomingPostbackResult::Error, 'unknown driver', Response::HTTP_NOT_FOUND);
        }

        $log->update(['network_id' => $network->id]);

        if (! $this->ipAllowed($request, $network)) {
            return $this->stop(
                $log,
                IncomingPostbackResult::Rejected,
                'source ip not allowed',
                Response::HTTP_FORBIDDEN,
                ['ip_allowed' => false],
            );
        }

        $log->update(['ip_allowed' => true]);

        try {
            $adapter = $this->drivers->make($network);
        } catch (UnknownDriverException) {
            // A row whose driver class was removed can never parse, so the
            // reversal exemption below can never rescue it — and "the network
            // is off" is the truer thing to tell an admin than a missing class
            // they cannot do anything about.
            return $network->status !== NetworkStatus::Active
                ? $this->stop($log, IncomingPostbackResult::Rejected, self::INACTIVE_REASON, Response::HTTP_FORBIDDEN)
                : $this->stop($log, IncomingPostbackResult::Error, 'driver not registered', Response::HTTP_NOT_FOUND);
        }

        if (! $adapter->verifyPostback($request)) {
            return $this->stop(
                $log,
                IncomingPostbackResult::Rejected,
                'invalid signature',
                Response::HTTP_FORBIDDEN,
                ['signature_valid' => false],
            );
        }

        $log->update(['signature_valid' => true]);

        $dto = $adapter->parsePostback($request);

        if ($dto === null) {
            return $this->stop($log, IncomingPostbackResult::Error, 'unparseable postback', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Every network ships `disabled` and is switched on only once an admin
        // has pasted credentials. Without this gate every seeded row is
        // a live money endpoint from the moment the app boots.
        //
        // A reversal is exempt, and the gate has to sit after the parse to know
        // that. What the gate protects against is a never-configured network
        // *earning*; `reverse()` matches on `(network_id, network_txid)` and can
        // only take money off a conversion already recorded under this same
        // network, of which a network that was never switched on has none.
        // Refusing one is never the safe direction: a network disabled after it
        // ran — a failed sync, an admin pulling it for the afternoon — is
        // exactly when its chargebacks arrive, and a 403 leaves the publisher
        // holding money the advertiser already took back. The two gates that
        // authenticate the sender, the IP allowlist and the signature, are both
        // upstream of here and still apply.
        if ($network->status !== NetworkStatus::Active && ! $dto->isReversal()) {
            return $this->stop(
                $log,
                IncomingPostbackResult::Rejected,
                self::INACTIVE_REASON,
                Response::HTTP_FORBIDDEN,
            );
        }

        $dto->isReversal()
            ? $this->processor->reverse($dto, $network, $log)
            : $this->processor->record($dto, $network, $log);

        // Lowercase because Offery documents it that way — "our servers expect
        // your postback endpoint to respond with `ok`; any other response will
        // be marked as failed" — and a network that marks our 200 as failed
        // retries conversions it was already told about. No other partner here
        // specifies a body at all, so the strictest published contract wins.
        return response('ok');
    }

    /**
     * An empty allowlist rejects, it does not admit.
     *
     * docs/00-overview.md §8 is explicit — "signature verification **and**
     * source IP allowlist per network. Both, not either." Admitting any source
     * when the list is empty turns the weaker of the two guarantees off exactly
     * when the stronger one may also be absent: OGAds signs nothing, so an
     * OGAds row with no allowlist and no shared key would be an unauthenticated
     * endpoint that moves money. The click_id an attacker needs is not secret
     * to the one person who most wants to abuse it — the end user reads it out
     * of the redirect URL they were just sent through.
     *
     * Empty is still refuse unless `allow_any_postback_ip` is on. That flag
     * is the default for a new network; a blank repeater never implies admit
     * on its own. Turning the flag off restores the IP gate.
     */
    private function ipAllowed(Request $request, Network $network): bool
    {
        if ($network->allow_any_postback_ip) {
            return true;
        }

        $allowed = $network->postback_ips;

        return filled($allowed) && in_array($request->ip(), $allowed, true);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function stop(
        IncomingPostback $log,
        IncomingPostbackResult $result,
        string $reason,
        int $status,
        array $extra = [],
    ): Response {
        $log->update([...$extra, 'result' => $result, 'reject_reason' => $reason]);

        return response($reason, $status);
    }
}
