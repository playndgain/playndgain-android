<?php

namespace App\Services\Money;

use App\Enums\ConversionStatus;
use App\Enums\OutgoingPostbackKind;
use App\Jobs\DispatchPublisherPostback;
use App\Models\App;
use App\Models\Conversion;
use App\Models\Offer;
use App\Support\TransactionId;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The "Send test postback" button behind both panels — docs/07 §5, docs/08 §4.
 *
 * It resolves the publisher's own template through the real MacroResolver, with
 * the app's real secret in the signature, and sends the real request. What the
 * caller gets back is the status, the body and the duration, because "it didn't
 * work" tickets are settled by exactly those three things.
 *
 * ponytail: synchronous, and it does not persist a conversion or an
 * outgoing_postbacks row. The retry ladder is the one part not exercised;
 * everything that actually breaks — macro expansion, signature, the publisher's
 * endpoint — is identical to production. Persisting a fake conversion to
 * exercise the queue would put a row in the money tables to test a button.
 */
final class TestPostbackService
{
    public const TEST_USER_ID = 'playndgain-test-user';

    /**
     * @return array{ok: bool, status: ?int, body: string, duration_ms: int, url: string}
     */
    public function send(
        App $app,
        OutgoingPostbackKind $kind = OutgoingPostbackKind::Credit,
        ?string $userId = null,
    ): array {
        $url = MacroResolver::resolve($app->postback_url ?? '', $this->sample($app, $userId), $kind);
        $started = hrtime(true);

        try {
            $response = Http::timeout(15)
                ->withUserAgent(DispatchPublisherPostback::USER_AGENT)
                ->send($app->postback_method->value, $url);
        } catch (ConnectionException $e) {
            return [
                'ok' => false,
                'status' => null,
                'body' => Str::limit($e->getMessage(), 500),
                'duration_ms' => $this->elapsed($started),
                'url' => UrlRedactor::redact($url),
            ];
        }

        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'body' => Str::limit($response->body(), 500),
            'duration_ms' => $this->elapsed($started),
            'url' => UrlRedactor::redact($url),
        ];
    }

    /**
     * An unsaved conversion, so nothing reaches the money tables. `is_test`
     * mirrors what a real test-mode conversion would carry.
     */
    private function sample(App $app, ?string $userId = null): Conversion
    {
        $payout = '0.100000';
        $endUserId = trim((string) $userId);

        $conversion = new Conversion([
            'trans_id' => TransactionId::generate('test'),
            'network_txid' => 'TEST-'.Str::upper(Str::random(8)),
            'app_id' => $app->id,
            'publisher_id' => $app->publisher_id,
            'end_user_id' => $endUserId !== '' ? $endUserId : self::TEST_USER_ID,
            'offer_name' => 'PLAYNDGAIN test offer',
            'revenue' => $payout,
            'payout' => $payout,
            'rev_share_applied' => '100.00',
            'currency_amount' => Money::currency($payout, $app->currency_rate),
            'status' => ConversionStatus::Approved,
            'is_test' => true,
            'country' => 'US',
            'ip' => '127.0.0.1',
        ]);

        // Relations, not a save: MacroResolver reads `app->app_id` and the
        // secret through them. `external_id` is `0` so `{offer_id}` is present
        // — an empty campaign id is what made some handlers look up null.
        $offer = new Offer(['external_id' => '0', 'name' => 'PLAYNDGAIN test offer']);

        $conversion->setRelation('app', $app);
        $conversion->setRelation('offer', $offer);

        return $conversion;
    }

    private function elapsed(float $started): int
    {
        return (int) ((hrtime(true) - $started) / 1e6);
    }
}
