<?php

namespace App\Services\Wall;

use App\Enums\FraudAction;
use App\Enums\FraudStage;
use App\Fraud\Contracts\IpIntelligence;
use App\Fraud\Contracts\RenderGateRule;
use App\Fraud\FraudContext;
use App\Fraud\IpScoreHistogram;
use App\Fraud\MaxMindIpIntelligence;
use App\Fraud\RuleRegistry;
use App\Models\App;
use App\Models\BannedEndUser;
use App\Models\Consent;
use App\Models\FraudEvent;
use App\Models\FraudRule;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Steps 1–9 of the render pipeline (docs/03 §2). All of it is cheap and has to
 * stay cheap: one indexed app lookup, one cached IP verdict, one string parse,
 * one query of enabled pre-click block rules, and — only for the countries
 * that need them — one consent row and one ban row.
 */
class WallGate
{
    public function __construct(
        private readonly IpIntelligence $ipIntelligence,
        private readonly DeviceDetector $devices,
        private readonly RuleRegistry $rules,
    ) {}

    /** Step 1. 404 when the public app ID does not resolve. */
    public function app(Request $request): App
    {
        $appId = self::param($request, 'appid');

        abort_unless(ctype_digit($appId), Response::HTTP_NOT_FOUND);

        /** @var App */
        return App::query()
            ->with('publisher')
            ->where('app_id', (int) $appId)
            ->firstOrFail();
    }

    /**
     * Normalised to a string whatever arrived: the same parameters come in as
     * query strings on the wall and as form fields on the consent POST, and a
     * form post of a numeric ID arrives as an int.
     */
    private static function param(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_scalar($value) ? (string) $value : '';
    }

    /** Step 2. A paused app or an unapproved publisher gets the unavailable page, not a 404. */
    public function isServable(App $app): bool
    {
        return $app->isActive() && (bool) $app->publisher?->isApproved();
    }

    /** Steps 3–6. */
    public function context(Request $request, App $app): WallContext
    {
        $userId = $this->endUserId($request);

        abort_if(
            $app->require_signed_wall && ! self::signatureValid($app, self::param($request, 'appid'), $userId, $request->input('sig')),
            Response::HTTP_FORBIDDEN,
        );

        $ip = (string) $request->ip();
        $testMode = self::testMode($app);
        $ua = $request->userAgent();
        $agent = $this->devices->detect($ua);
        $verdict = $this->ipIntelligence->inspect($ip);

        // Every impression, refused and served alike — this is the only point
        // that sees both sides of the risk threshold, which is the whole reason
        // the histogram is sampled here rather than off a click row or a fraud
        // event. See IpScoreHistogram. A null score is skipped there, so this
        // costs nothing at all until a scoring provider is configured.
        IpScoreHistogram::record($verdict->riskScore);

        $override = $this->countryOverride($request, $testMode);

        return new WallContext(
            app: $app,
            endUserId: $userId,
            ip: $ip,
            country: $override ?? $verdict->country,
            verdict: $verdict,
            device: $agent['device'],
            os: $agent['os'],
            osVersion: $agent['os_version'],
            browser: $agent['browser'],
            userAgent: $ua,
            referrer: $request->headers->get('referer'),
            locale: $this->locale($request),
            theme: WallContext::sanitiseTheme($app->theme, $this->stringParam($request, 'theme')),
            testMode: $testMode,
            balance: $this->stringParam($request, 'balance'),
            countryOverridden: $override !== null,
        );
    }

    /**
     * Step 4. The publisher's own identifier, echoed back in every postback, so
     * it is stored and compared but never interpreted. Rejecting the characters
     * that break a URL or a log line is the whole contract.
     */
    public function endUserId(Request $request): string
    {
        $userId = self::param($request, 'userid');

        abort_unless(
            preg_match('/^[A-Za-z0-9._:@|\-]{1,191}$/', $userId) === 1,
            Response::HTTP_BAD_REQUEST,
        );

        return $userId;
    }

    /**
     * `sha256(appid + userid + secret_key)`, docs/03 §1.
     *
     * Application code asks for the signature rather than writing it out, so
     * the rule has one home. `tests/Feature/Wall/WallFixtures.php` keeps its own
     * hand-written copy on purpose — a signing test that calls the signer it is
     * testing agrees with any formula, including a wrong one.
     */
    public static function sign(App $app, string $userId): string
    {
        return hash('sha256', (string) $app->app_id.$userId.(string) $app->secret_key);
    }

    public static function signatureValid(App $app, string $appId, string $userId, mixed $signature): bool
    {
        if (! is_string($signature) || $signature === '') {
            return false;
        }

        return hash_equals(hash('sha256', $appId.$userId.(string) $app->secret_key), strtolower($signature));
    }

    /**
     * The second half of the consent-before-escalation rule (docs/05 §4). The
     * first `inspect()` in `context()` cannot escalate for a consent-country
     * address because consent is not resolved yet — the country it returns is
     * what resolves it. Once a granted consent is in hand, this asks again with
     * permission.
     *
     * Callers must gate this on an actual granted consent, never merely on
     * `consentRequired($context->country)`: that country can be a test-mode
     * `?country=` override, which names a country that never required consent
     * while the real IP underneath it might be one that does — calling this
     * unconditionally would ask a provider about that real IP without ever
     * having asked the user.
     */
    public function escalate(WallContext $context): WallContext
    {
        $verdict = $this->ipIntelligence->inspect($context->ip, consented: true);

        return new WallContext(...[
            ...get_object_vars($context),
            'verdict' => $verdict,
            // `context()` keeps the override over the verdict's own country for
            // the same reason (Step 5's countryOverride()); escalation must not
            // undo that by leaking the real country into what a publisher asked
            // to preview.
            'country' => $context->countryOverridden ? $context->country : $verdict->country,
        ]);
    }

    /**
     * Step 5. Unknown country is fail-closed downstream, and closed all the
     * way: an address GeoLite2 cannot place gets an empty wall, not the
     * worldwide inventory. Showing an offer to a user whose eligibility we
     * never established costs a chargeback, and a chargeback costs more than
     * an unshown offer. Same rule for a user agent that parses to no device.
     */
    private function countryOverride(Request $request, bool $testMode): ?string
    {
        if (! $testMode) {
            return null;
        }

        $override = $this->stringParam($request, 'country');

        return $override !== null && preg_match('/^[A-Za-z]{2}$/', $override) === 1
            ? strtoupper($override)
            : null;
    }

    /** Step 7. */
    public function consentRequired(?string $country): bool
    {
        return $country !== null && in_array($country, Setting::consentCountries(), true);
    }

    public function consent(App $app, string $endUserId): ?Consent
    {
        return Consent::query()
            ->where('app_id', $app->id)
            ->where('end_user_id', $endUserId)
            ->first();
    }

    /**
     * Step 8. Checked here rather than left to the `banned_end_user` fraud rule:
     * the rule row is seeded but its class lands with phase 3, and until then
     * the pipeline would resolve nothing and return a clean decision.
     */
    public function isBanned(App $app, string $endUserId): bool
    {
        return BannedEndUser::query()
            ->withoutGlobalScopes()
            ->where('publisher_id', $app->publisher_id)
            ->where(fn (Builder $query) => $query->whereNull('app_id')->orWhere('app_id', $app->id))
            ->where('end_user_id', $endUserId)
            ->active()
            ->exists();
    }

    /**
     * The last refusal before the offers table is touched — docs/05 §5.
     *
     * Reads the `fraud_rules` rows an admin already tunes rather than a second
     * set of toggles, so one signal is configured in one place. Only rules
     * marked `RenderGateRule` are eligible: their sole input is the verdict, so
     * running them here costs one array read and cannot misinterpret an
     * impression as a click.
     *
     * @return ?string A `wall.notice.*` key, or null to serve the wall.
     */
    public function protectionBlock(WallContext $context): ?string
    {
        $rows = FraudRule::query()
            ->where('stage', FraudStage::PreClick)
            ->where('action', FraudAction::Block)
            ->where('enabled', true)
            ->get();

        $fraud = new FraudContext(
            app: $context->app,
            endUserId: $context->endUserId,
            ip: $context->ip,
            country: $context->country,
            userAgent: $context->userAgent,
            referrer: $context->referrer,
            // Already resolved in context() (and, once a consent gate has
            // granted, in escalate()) — carrying it here is what keeps the
            // render gate's five IP rules from each re-deriving it themselves.
            verdict: $context->verdict,
        );

        foreach ($rows as $row) {
            $rule = $this->rules->resolve($row->key);

            if ($rule === null) {
                // An admin can create a rule row from the panel for a key
                // that has no class behind it. Skip it loudly rather than
                // taking the wall down — mirrors FraudPipeline::evaluate().
                Log::warning('Fraud rule row has no registered class', ['key' => $row->key]);

                continue;
            }

            if (! $rule instanceof RenderGateRule) {
                continue;
            }

            try {
                $result = $rule->evaluate($fraud, $row->config ?? []);
            } catch (Throwable $e) {
                // This runs on every wall impression, not one click, so a
                // rule that throws here must not take the whole offerwall
                // down with it. It is a signal that failed, not a verdict —
                // mirrors FraudPipeline::evaluate().
                Log::error('Fraud rule threw', ['key' => $row->key, 'error' => $e->getMessage()]);

                continue;
            }

            if (! $result->triggered) {
                continue;
            }

            // Nothing blocks silently — docs/05 §1. The block itself is
            // never suppressed by this cache; only the audit row is. Without
            // it, a blocked address that keeps reloading writes one
            // `fraud_event` per request, burying the row an admin needs
            // under duplicates of itself. One row per app + end user + IP +
            // rule per hour is still enough to answer "why was this user
            // blocked". `endUserId` and an IPv6 `ip` can both contain `:`, so
            // the tuple is hashed rather than joined with a delimiter — a
            // concatenated key lets "ab:2001" + "db8::1" collide with "ab" +
            // "2001:db8::1", silently dropping a real audit row.
            $dedupeKey = "fraud_event_dedupe:{$row->key}:".sha1(json_encode([
                $context->app->id,
                $context->endUserId,
                $context->ip,
            ]));

            if (Cache::add($dedupeKey, true, MaxMindIpIntelligence::TTL)) {
                FraudEvent::record($row, FraudStage::PreClick, $fraud, $result);
            }

            // The rule decides whether its own trigger is worth refusing a
            // render for — `RenderGateRule::noticeKey()`'s docblock is why.
            // Null (VpnProxyIpRule's unclassified fallback) keeps evaluating
            // the rest of the rows rather than serving the wall outright: the
            // fraud_event above still stands, only the refusal is withheld.
            $notice = $rule->noticeKey($result);

            if ($notice !== null) {
                return $notice;
            }
        }

        return null;
    }

    /**
     * docs/03 §7. While on, the `country` parameter is honoured so a publisher
     * can preview any geo, and the test offer is pinned above the wall. Off is
     * the default, and it has to be: `country` is a targeting override, and an
     * override that works on a live app is a way to be served the offers the
     * targeting refused.
     */
    public static function testMode(App $app): bool
    {
        return (bool) $app->test_mode;
    }

    private function locale(Request $request): string
    {
        $lang = $this->stringParam($request, 'lang');

        return in_array($lang, WallContext::LOCALES, true) ? $lang : 'en';
    }

    private function stringParam(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
