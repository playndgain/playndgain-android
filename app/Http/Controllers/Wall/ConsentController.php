<?php

namespace App\Http\Controllers\Wall;

use App\Http\Controllers\Controller;
use App\Services\Wall\ConsentRecorder;
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

        ConsentRecorder::record($context, $action === 'accept');

        // Back to the wall, which re-reads the row it just wrote — one code
        // path decides what a granted or declined consent renders.
        return redirect()->to(route('wall').'?'.$context->params(), Response::HTTP_FOUND);
    }
}
