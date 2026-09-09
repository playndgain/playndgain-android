<?php

namespace App\Http\Controllers;

use App\Models\NewsletterSubscriber;
use App\Services\Wall\IpAnonymizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The footer's mailing list. The same shape as the contact form, for the same
 * reasons: a plain POST to this app with a CSRF token, no third-party form
 * service, no JavaScript between the button and the row — it works with the
 * bundle blocked.
 *
 * The row that comes out is a consent record, not just an address: the page it
 * was given on, roughly where from, and when. Off the list is a signed GET on
 * the same controller.
 */
class NewsletterController extends Controller
{
    /** One string, so the toast, the flash and the tests cannot drift apart. */
    public const CONFIRMATION = 'You are on the list.';

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        // Its own error bag: the contact form also has an `email` field, and
        // a footer that shows the contact form's mistakes is a footer that
        // lies about which form was wrong.
        $request->validateWithBag('newsletter', [
            'email' => ['required', 'string', 'email', 'max:190'],
        ]);

        // The honeypot. A field no person sees, so anything that filled it is
        // a script — which gets the same thank-you as everyone else and no row.
        if ($request->filled('company')) {
            return $this->done($request);
        }

        $email = mb_strtolower(trim($request->string('email')->toString()));

        // firstOrCreate rather than a select-then-insert: two identical submits
        // arriving together would otherwise race, and the unique index would
        // turn the loser into a 500 on a button press.
        $subscriber = NewsletterSubscriber::query()->firstOrCreate(
            ['email' => $email],
            [
                'source' => $this->source($request),
                'ip' => IpAnonymizer::mask((string) $request->ip()),
            ],
        );

        // Coming back after an unsubscribe is a fresh consent, so the record is
        // taken again — page, address and all. Refusing instead would be an
        // argument with someone who just asked to be on the list.
        //
        // A press from someone already on it changes nothing. The consent that
        // counts is the one they first gave, and overwriting `source` with
        // whatever page they happened to be on would make `created_at` describe
        // a page they were never on.
        if (! $subscriber->wasRecentlyCreated && ! $subscriber->isSubscribed()) {
            $subscriber->update([
                'source' => $this->source($request),
                'ip' => IpAnonymizer::mask((string) $request->ip()),
                'unsubscribed_at' => null,
            ]);
        }

        return $this->done($request);
    }

    /**
     * Off the list. A signed GET, so the link in a mail footer works from any
     * mailbox with no session and nothing to log into, and a crawler that
     * follows every URL on the page cannot reach it without the signature.
     *
     * Idempotent: a second click on the same link says the same thing rather
     * than reporting an error at somebody who is already gone.
     */
    public function unsubscribe(NewsletterSubscriber $subscriber): View
    {
        if ($subscriber->isSubscribed()) {
            $subscriber->update(['unsubscribed_at' => now()]);
        }

        return view('newsletter.unsubscribed', ['email' => $subscriber->email]);
    }

    /**
     * Which page the address was given on. The footer renders site-wide, so
     * 'landing' was wrong for everyone who subscribed from the docs.
     *
     * Read from the referer, and only ever the path from a referer on this
     * host — a header a client controls is not something to store whole.
     */
    private function source(Request $request): string
    {
        $referer = (string) $request->headers->get('referer');

        if ($referer === '' || parse_url($referer, PHP_URL_HOST) !== $request->getHost()) {
            return 'direct';
        }

        $path = (string) (parse_url($referer, PHP_URL_PATH) ?: '/');

        return mb_substr($path, 0, 190);
    }

    /**
     * Two answers to the same press.
     *
     * With the bundle in, site.js posts this in the background and the page
     * never reloads — so the answer is JSON and the toast says it without
     * anything moving. With the bundle blocked it is the redirect it always
     * was: back to the form, fragment and all, where the footer renders the
     * flash in place. Neither path knows about the other.
     */
    private function done(Request $request): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => self::CONFIRMATION]);
        }

        return redirect()
            ->back()
            ->withFragment('newsletter')
            ->with('newsletter', self::CONFIRMATION);
    }
}
