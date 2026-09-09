<?php

namespace App\Http\Controllers;

use App\Mail\ContactMessage;
use App\Support\Identity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use Throwable;

/**
 * The contact form — docs/09 §7 routes it to this app, so there is no CORS
 * preflight, no third-party form service, and no JavaScript between the submit
 * button and the mailbox. It is a plain POST with a CSRF token and it works
 * with the bundle blocked.
 *
 * The topic is an allowlist rather than free text because it is the only field
 * that reaches a mail header. Everything the sender typed goes in the body.
 *
 * Delivery goes to Identity::supportEmail(), never to `mail.from.address` —
 * the from-address is a no-reply nobody reads.
 */
class ContactController extends Controller
{
    /**
     * Keyed by the value that travels in the form and the query string; the
     * label is what the sender picked and what the subject line says.
     *
     * `/advertise` and `/status` deep-link into this list with `?topic=`, so a
     * key renamed here is a dead pre-selection there — the test asserts the two
     * links resolve to real options.
     *
     * @return array<string, string>
     */
    public static function topics(): array
    {
        return [
            'publisher' => 'Becoming a publisher',
            'technical' => 'Integration or technical problem',
            'payouts' => 'Payouts and account',
            'supply' => 'Supplying offers to '.Identity::name(),
            'privacy' => 'Privacy or a data request',
            'other' => 'Something else',
        ];
    }

    public function show(Request $request): View
    {
        return view('contact', [
            'topics' => self::topics(),
            // Only a default. `old()` wins on the way back from a failed
            // validation, so a sender who arrived from /advertise and then
            // mistyped their email does not lose the topic they chose.
            'selected' => $request->string('topic')->toString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'topic' => ['required', 'string', 'in:'.implode(',', array_keys(self::topics()))],
            // A floor as well as a ceiling: two words is not a question anyone
            // can answer, and an unbounded textarea is a mail server's problem.
            'message' => ['required', 'string', 'min:20', 'max:5000'],
        ]);

        // Read back through the typed accessors rather than the validator's
        // array, which is `mixed` all the way down and would need a cast per
        // field to reach a typed constructor.
        $message = new ContactMessage(
            senderName: $request->string('name')->toString(),
            senderEmail: $request->string('email')->toString(),
            topic: self::topics()[$request->string('topic')->toString()],
            body: $request->string('message')->toString(),
        );

        // The support mailbox, not `mail.from.address`. Those are two different
        // addresses on purpose: mail leaves from a no-reply that nobody watches,
        // and a contact form delivered there is a form nobody answers.
        // Identity::supportEmail() is the same resolution every other mailbox in
        // the app uses, so a Settings row overrides it without a deploy.
        try {
            Mail::to(Identity::supportEmail())->send($message);
        } catch (Throwable $e) {
            // A transport that is down must not become a 500 on top of a message
            // that is already lost. The sender gets the address they can write
            // to by hand and their text is still in the form behind them.
            Log::error('Contact form could not be delivered.', [
                'exception' => $e->getMessage(),
                'sender' => $request->string('email')->toString(),
                'topic' => $request->string('topic')->toString(),
            ]);

            return redirect()
                ->route('contact')
                ->withInput()
                ->with('delivery_failed', 'We could not send that just now. Write to '.Identity::supportEmail().' and it reaches the same desk.');
        }

        // Redirect rather than render, so a refresh on the thank-you does not
        // re-send. The flash is read by a role="status" region on the form page.
        return redirect()
            ->route('contact')
            ->with('status', 'Thanks — your message is with us. We reply to everything, usually within one working day.');
    }
}
