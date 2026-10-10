<?php

namespace App\Http\Controllers\Wall;

use App\Http\Controllers\Controller;
use App\Services\Wall\HistoryFeed;
use App\Services\Wall\WallGate;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `GET /wall/history` — this end user's own clicks and what each one earned.
 *
 * Steps 1–8 are `WallGate`, in full and in the same order as `WallController`.
 * Step 7 is the one worth naming here too: a user who declined data processing
 * must not reach their own click history by deep link, and the history of a
 * user is exactly the data they declined. `Cache-Control: private, no-store`
 * comes from `RendersWallPages` and is not optional on a page that is per end
 * user by definition.
 *
 *   9.  This user's click rows, newest 50, paired with their conversions
 *   10. Plus what the embedded partner walls paid, told to us by postback
 *   11. Render
 */
class HistoryController extends Controller
{
    use RendersWallPages;

    public function __construct(
        private readonly WallGate $gate,
    ) {}

    public function __invoke(Request $request): Response
    {
        $app = $this->gate->app($request);

        if (! $this->gate->isServable($app)) {
            return $this->notice('unavailable', status: Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $context = $this->gate->context($request, $app);

        // The same gate as the wall, minus two steps it has never run here: no
        // render gate (a blocked user may still read their own past clicks) and
        // no consented re-lookup of the IP.
        $admission = $this->gate->admit(
            $request,
            $context,
            protect: false,
            reviewConsent: $request->input('consent') === 'review',
            escalate: false,
        );
        $context = $admission->context;

        return match ($admission->refusal) {
            null => $this->page('history', [
                'context' => $context,
                'rows' => HistoryFeed::rows($context),
            ]),
            'consent' => $this->page('consent', ['context' => $context]),
            'declined' => $this->notice('declined', $context),
            default => $this->notice('banned', $context),
        };
    }
}
