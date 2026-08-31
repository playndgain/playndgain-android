<?php

namespace App\Http\Controllers\Wall;

use App\Http\Controllers\Controller;
use App\Models\Consent;
use App\Services\Wall\IpAnonymizer;
use App\Services\Wall\WallGate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EU consent gate — docs/03-offerwall.md §4.
 *
 * The screen is plain, not a dark pattern: what is collected, why, who
 * receives it, and two equally-weighted buttons. Decline is honoured — it
 * writes `granted=false`, shows an explanation, and no offers are rendered and
 * no click is possible.
 *
 * Consent is stored per (app, end_user), never per session, so it survives the
 * iframe reloading.
 */
class ConsentController extends Controller
{
    use RendersWallPages;

    /** Written to the row so a later purpose change is visibly a different consent. */
    private const PURPOSES = ['fraud_prevention', 'reward_attribution', 'network_delivery'];

    public function __construct(private readonly WallGate $gate) {}

    public function store(Request $request): Response
    {
        $app = $this->gate->app($request);

        if (! $this->gate->isServable($app)) {
            return $this->notice('unavailable', status: Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $context = $this->gate->context($request, $app);
        $action = $request->input('action');

        abort_unless(in_array($action, ['accept', 'decline'], true), Response::HTTP_BAD_REQUEST);

        Consent::query()->updateOrCreate(
            ['app_id' => $app->id, 'end_user_id' => $context->endUserId],
            [
                'granted' => $action === 'accept',
                'purposes' => self::PURPOSES,
                // Evidence of consent, so it follows the app's own IP rule
                // rather than storing more than the wall itself would.
                'ip' => $app->anonymize_ip ? IpAnonymizer::mask($context->ip) : $context->ip,
                'country' => $context->country,
                // Hashed: the row proves which client agreed without keeping a
                // fingerprintable string for the life of the account.
                'user_agent_hash' => hash('sha256', (string) $context->userAgent),
            ],
        );

        // Back to the wall, which re-reads the row it just wrote — one code
        // path decides what a granted or declined consent renders.
        return redirect()->to(route('wall').'?'.$context->params(), Response::HTTP_FOUND);
    }
}
