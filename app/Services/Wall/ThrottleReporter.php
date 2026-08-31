<?php

namespace App\Services\Wall;

use App\Enums\FraudActionTaken;
use App\Enums\FraudStage;
use App\Models\FraudEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * The 429 body and the fraud event behind it — docs/03 §6.
 *
 * Sustained rate-limit hits are a fraud signal, not only a load signal, so
 * exceeding a wall limit is recorded rather than silently shed.
 *
 * ORCHESTRATOR: this needs one line per limiter in
 * `AppServiceProvider::rateLimiters()`, which is outside this phase's scope:
 *
 *     Limit::perMinute(60)->by($request->ip())
 *         ->response(fn (Request $request) => app(ThrottleReporter::class)->report($request));
 *
 * Until that lands the limiter still returns Laravel's own 429 and no event is
 * written.
 */
class ThrottleReporter
{
    /** One event per key per minute. A flood would otherwise write a row per request. */
    private const DEDUPE_TTL = 60;

    public function report(Request $request): Response
    {
        $ip = (string) $request->ip();
        $endUserId = $request->query('userid');
        $endUserId = is_string($endUserId) ? substr($endUserId, 0, 191) : null;

        if (Cache::add('wall:throttled:'.sha1($ip.'|'.$request->path()), true, self::DEDUPE_TTL)) {
            FraudEvent::create([
                'rule_key' => 'rate_limit',
                'stage' => FraudStage::PreClick,
                // No subject: the request never became a click row, and
                // resolving the app on a shed request would defeat the point.
                'subject_type' => '',
                'subject_id' => 0,
                'end_user_id' => $endUserId,
                'action_taken' => FraudActionTaken::Flagged,
                'context' => [
                    'reason' => 'rate_limit_exceeded',
                    'ip' => $ip,
                    'path' => $request->path(),
                    'appid' => $request->query('appid'),
                ],
                'created_at' => now(),
            ]);
        }

        return new Response(
            __('wall.too_many'),
            Response::HTTP_TOO_MANY_REQUESTS,
            ['Cache-Control' => 'private, no-store', 'Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }
}
