<?php

namespace App\Identity;

use App\Enums\IdentityKind;
use App\Enums\IdentitySignal;
use App\Enums\UserRole;
use App\Http\Controllers\ImpersonationController;
use App\Models\AccountMatch;
use App\Models\IdentitySession;
use App\Models\Publisher;
use App\Models\User;
use App\Services\Wall\WallContext;
use App\Support\AdminAlert;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Append-only session log and cross-account matches. Not a fraud rule.
 *
 * Wall matches never email, never write a fraud_event, and never refuse a
 * request. Panel matches email once per new pair. A throw here is logged and
 * swallowed — the wall and login still succeed.
 */
final class IdentityLedger
{
    public function recordPanel(User $user, Request $request): void
    {
        try {
            $this->writePanel($user, $request);
        } catch (Throwable $e) {
            Log::error('identity ledger failed', ['kind' => 'panel', 'error' => $e->getMessage()]);
        }
    }

    public function recordWall(WallContext $context, Request $request): void
    {
        try {
            $this->writeWall($context, $request);
        } catch (Throwable $e) {
            Log::error('identity ledger failed', ['kind' => 'wall', 'error' => $e->getMessage()]);
        }
    }

    private function writePanel(User $user, Request $request): void
    {
        if ($request->hasSession() && $request->session()->has(ImpersonationController::SESSION_KEY)) {
            return;
        }

        $ip = (string) $request->ip();
        $ua = (string) $request->userAgent();

        if ($ip === '') {
            return;
        }

        $session = IdentitySession::query()->create([
            'kind' => IdentityKind::Panel,
            'user_id' => $user->id,
            'publisher_id' => $user->publisher?->id,
            'ip' => $ip,
            'ua_hash' => hash('sha256', $ua),
            'ds_hash' => DeviceSignals::hash(is_string($request->input('ds')) ? $request->input('ds') : null),
            'session_id' => $request->hasSession() ? $request->session()->getId() : null,
            'created_at' => now(),
        ]);

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ])->save();

        if (! $user->isPublisher()) {
            return;
        }

        $this->matchPanel($session);
    }

    private function writeWall(WallContext $context, Request $request): void
    {
        if ($context->testMode) {
            return;
        }

        $ip = (string) $context->ip;
        $ua = (string) $context->userAgent;
        $dsHash = DeviceSignals::hash(is_string($request->input('ds')) ? $request->input('ds') : null);

        if ($ip === '') {
            return;
        }

        $dedupe = 'identity_session:'.sha1((string) json_encode([
            $context->app->id,
            $context->endUserId,
            $ip,
            $dsHash ?? '',
        ]));

        if (! Cache::add($dedupe, true, 3600)) {
            return;
        }

        $session = IdentitySession::query()->create([
            'kind' => IdentityKind::Wall,
            'publisher_id' => $context->app->publisher_id,
            'app_id' => $context->app->id,
            'end_user_id' => $context->endUserId,
            'country' => self::countryCode($context->country),
            'ip' => $ip,
            'ua_hash' => hash('sha256', $ua),
            'ds_hash' => $dsHash,
            'created_at' => now(),
        ]);

        $this->matchWall($session);
    }

    /**
     * The country the wall already resolved for this request — live mode has
     * no `?country=` override, so this is the IP verdict's. `XX` is the
     * project's own "unknown" placeholder and is stored as null, never as a
     * country.
     */
    private static function countryCode(?string $country): ?string
    {
        $code = strtoupper(trim((string) $country));

        return preg_match('/^[A-Z]{2}$/', $code) === 1 && $code !== 'XX' ? $code : null;
    }

    private function matchPanel(IdentitySession $session): void
    {
        $this->matchPanelColumn($session, IdentitySignal::Ip, $session->ip, 'ip');
        $this->matchPanelColumn($session, IdentitySignal::Ds, $session->ds_hash, 'ds_hash');
        $this->matchPanelColumn($session, IdentitySignal::Session, $session->session_id, 'session_id');
    }

    private function matchPanelColumn(IdentitySession $session, IdentitySignal $signal, ?string $value, string $column): void
    {
        if ($value === null || $value === '' || $session->user_id === null) {
            return;
        }

        $ids = IdentitySession::query()
            ->where('kind', IdentityKind::Panel)
            ->where($column, $value)
            ->where('user_id', '!=', $session->user_id)
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id');

        $others = User::query()
            ->whereIn('id', $ids)
            ->where('role', UserRole::Publisher)
            ->pluck('id');

        foreach ($others as $other) {
            $this->upsertMatch(
                IdentityKind::Panel,
                $signal,
                $value,
                null,
                (int) $session->user_id,
                (int) $other,
                null,
                null,
                $session->publisher_id ? Publisher::query()->find($session->publisher_id) : null,
            );
        }
    }

    private function matchWall(IdentitySession $session): void
    {
        $this->matchWallColumn($session, IdentitySignal::Ip, $session->ip, 'ip');
        $this->matchWallColumn($session, IdentitySignal::Ds, $session->ds_hash, 'ds_hash');
    }

    private function matchWallColumn(IdentitySession $session, IdentitySignal $signal, ?string $value, string $column): void
    {
        if ($value === null || $value === '' || $session->end_user_id === null || $session->publisher_id === null) {
            return;
        }

        $others = IdentitySession::query()
            ->where('kind', IdentityKind::Wall)
            ->where('publisher_id', $session->publisher_id)
            ->where($column, $value)
            ->where('end_user_id', '!=', $session->end_user_id)
            ->whereNotNull('end_user_id')
            ->distinct()
            ->pluck('end_user_id');

        foreach ($others as $other) {
            $this->upsertMatch(
                IdentityKind::Wall,
                $signal,
                $value,
                (int) $session->publisher_id,
                null,
                null,
                $session->end_user_id,
                (string) $other,
                null,
            );
        }
    }

    private function upsertMatch(
        IdentityKind $scope,
        IdentitySignal $signal,
        string $value,
        ?int $publisherId,
        ?int $leftUserId,
        ?int $rightUserId,
        ?string $leftEndUserId,
        ?string $rightEndUserId,
        ?Publisher $alertSubject,
    ): void {
        if ($scope === IdentityKind::Panel) {
            [$leftUserId, $rightUserId] = collect([(int) $leftUserId, (int) $rightUserId])->sort()->values()->all();
            $matchKey = sha1(implode('|', ['panel', $signal->value, (string) $leftUserId, (string) $rightUserId]));
        } else {
            [$leftEndUserId, $rightEndUserId] = collect([(string) $leftEndUserId, (string) $rightEndUserId])->sort()->values()->all();
            $matchKey = sha1(implode('|', ['wall', $signal->value, (string) $publisherId, $leftEndUserId, $rightEndUserId]));
        }

        $match = AccountMatch::query()->firstOrNew(['match_key' => $matchKey]);
        $created = ! $match->exists;

        if ($created) {
            $match->fill([
                'scope' => $scope,
                'signal' => $signal,
                'publisher_id' => $publisherId,
                'left_user_id' => $leftUserId,
                'right_user_id' => $rightUserId,
                'left_end_user_id' => $leftEndUserId,
                'right_end_user_id' => $rightEndUserId,
                'seen_count' => 1,
                'first_seen_at' => now(),
            ]);
        } else {
            $match->seen_count = ((int) $match->seen_count) + 1;
        }

        $match->signal_value = $value;
        $match->last_seen_at = now();
        $match->save();

        if ($created && $scope === IdentityKind::Panel) {
            AdminAlert::raise("identity.panel_match.{$leftUserId}.{$rightUserId}", $alertSubject, [
                'signal' => $signal->value,
                'left_user_id' => $leftUserId,
                'right_user_id' => $rightUserId,
            ]);
        }
    }
}
