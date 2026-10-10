<?php

namespace App\Http\Controllers\Wall;

use App\Http\Controllers\Controller;
use App\Models\App;
use App\Models\Consent;
use App\Models\Conversion;
use App\Models\FraudEvent;
use App\Models\OfferClick;
use App\Services\Wall\IpAnonymizer;
use App\Services\Wall\WallGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * End-user data controls — docs/03-offerwall.md §4.
 *
 * Deletion anonymises clicks and conversions for the end user — IP redacted,
 * end_user_id replaced with a hash — and **retains the money rows**. Financial
 * records are not deletable; that is a legitimate-interest retention basis and
 * it has to be stated in the privacy policy rather than assumed.
 *
 * Both endpoints require a valid signature whatever `require_signed_wall` says.
 * The spec only demands it for deletion, but an unsigned `/privacy/data` hands
 * everything held on a user to anyone who can guess an app ID and a user ID,
 * which is the disclosure the endpoint exists to control.
 */
class PrivacyController extends Controller
{
    /** A subject access request is a summary plus evidence, not a table dump. */
    private const ROW_LIMIT = 100;

    /** Fraud-event inputs that describe the person or their device, dropped on delete. */
    private const FRAUD_CONTEXT_PII = ['ip', 'click_ip', 'conversion_ip', 'end_user_id', 'user_agent', 'device_hint', 'referrer'];

    public function __construct(private readonly WallGate $gate) {}

    public function show(Request $request): JsonResponse
    {
        [$app, $endUserId] = $this->authorizeSubject($request);

        $clicks = OfferClick::query()
            ->withoutGlobalScopes()
            ->where('app_id', $app->id)
            ->where('end_user_id', $endUserId);

        $conversions = Conversion::query()
            ->withoutGlobalScopes()
            ->where('app_id', $app->id)
            ->where('end_user_id', $endUserId);

        $consent = $this->gate->consent($app, $endUserId);

        return $this->json([
            'app_id' => $app->app_id,
            'end_user_id' => $endUserId,
            'consent' => $consent === null ? null : [
                'granted' => $consent->granted,
                'purposes' => $consent->purposes,
                'country' => $consent->country,
                'recorded_at' => $consent->created_at?->toIso8601String(),
            ],
            'clicks' => [
                'count' => (clone $clicks)->count(),
                'rows' => (clone $clicks)->latest('created_at')->limit(self::ROW_LIMIT)
                    ->get(['click_id', 'offer_id', 'ip', 'country', 'device_type', 'os', 'browser', 'sdk_platform', 'sdk_version', 'device_model', 'device_hint', 'sub1', 'sub2', 'sub3', 'sub4', 'sub5', 'created_at']),
            ],
            'conversions' => [
                'count' => (clone $conversions)->count(),
                'rows' => (clone $conversions)->latest('created_at')->limit(self::ROW_LIMIT)
                    ->get(['trans_id', 'offer_name', 'currency_amount', 'status', 'country', 'created_at']),
            ],
            'retention' => 'Financial records are retained under a legitimate-interest basis and are not erased by /privacy/delete.',
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        [$app, $endUserId] = $this->authorizeSubject($request);

        $pseudonym = IpAnonymizer::pseudonym((string) $app->secret_key, $endUserId);

        $result = DB::transaction(function () use ($app, $endUserId, $pseudonym): array {
            $clicks = OfferClick::query()
                ->withoutGlobalScopes()
                ->where('app_id', $app->id)
                ->where('end_user_id', $endUserId)
                ->update([
                    // `offer_clicks.ip` is NOT NULL and the row still has to
                    // reconcile against a conversion, so the column is redacted
                    // rather than dropped.
                    'ip' => IpAnonymizer::REDACTED,
                    'user_agent' => null,
                    'referrer' => null,
                    // Publisher-supplied and device-supplied strings: a sub-id
                    // can be anything a publisher chose to pass, an email
                    // included, and the model and build hint describe the
                    // user's own phone. None of it is needed to reconcile money.
                    'device_model' => null,
                    'device_hint' => null,
                    'sub1' => null,
                    'sub2' => null,
                    'sub3' => null,
                    'sub4' => null,
                    'sub5' => null,
                    'end_user_id' => $pseudonym,
                ]);

            $conversions = Conversion::query()
                ->withoutGlobalScopes()
                ->where('app_id', $app->id)
                ->where('end_user_id', $endUserId)
                ->update(['ip' => null, 'end_user_id' => $pseudonym]);

            // Fraud events keep the decision (rule, action, time) for audit,
            // not the inputs that identify the person or their phone.
            // Rate-limit events are written before the app is resolved, so
            // they carry the appid only inside their context, not in app_id.
            FraudEvent::query()
                ->where('end_user_id', $endUserId)
                ->where(fn ($q) => $q->where('app_id', $app->id)->orWhere(fn ($q) => $q
                    ->whereNull('app_id')
                    ->where('context->appid', (string) $app->app_id)))
                ->chunkById(500, function ($events) use ($pseudonym): void {
                    foreach ($events as $event) {
                        $event->forceFill([
                            'end_user_id' => $pseudonym,
                            'context' => Arr::except((array) $event->context, self::FRAUD_CONTEXT_PII),
                        ])->save();
                    }
                });

            // The consent row is the one thing that would still name them, and
            // it documents processing that is now over.
            Consent::query()->where('app_id', $app->id)->where('end_user_id', $endUserId)->delete();

            return ['clicks' => $clicks, 'conversions' => $conversions];
        });

        return $this->json([
            'status' => 'anonymized',
            'clicks_anonymized' => $result['clicks'],
            'conversions_anonymized' => $result['conversions'],
            'retained' => 'ledger_entries, conversions (financial fields)',
        ]);
    }

    /**
     * @return array{App, string}
     */
    private function authorizeSubject(Request $request): array
    {
        $app = $this->gate->app($request);
        $endUserId = $this->gate->endUserId($request);

        abort_unless(
            WallGate::signatureValid($app, (string) $app->app_id, $endUserId, $request->input('sig')),
            Response::HTTP_FORBIDDEN,
        );

        return [$app, $endUserId];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function json(array $payload): JsonResponse
    {
        return response()->json($payload)->header('Cache-Control', 'private, no-store');
    }
}
