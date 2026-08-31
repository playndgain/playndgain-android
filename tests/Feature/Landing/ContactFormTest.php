<?php

use App\Http\Controllers\ContactController;
use App\Mail\ContactMessage;
use Illuminate\Support\Facades\Mail;

it('renders a form that submits without JavaScript', function () {
    $html = $this->get('/contact')->assertOk()->getContent();

    expect($html)
        ->toContain('method="POST"')
        ->toContain('name="_token"')          // CSRF is in the markup, not fetched
        ->toContain('action="http://localhost/contact"')
        ->toContain('<textarea');
});

it('ties every label to its field and every hint or error to its input', function () {
    $this->from('/contact')->post('/contact', [
        'name' => '', 'email' => 'not-an-email', 'topic' => 'nope', 'message' => 'short',
    ]);

    $html = $this->get('/contact')->getContent();

    // No hint of their own, so the description appears only on the error path —
    // and the attribute appears with it.
    foreach (['name', 'topic'] as $field) {
        expect($html)
            ->toContain('for="'.$field.'"')
            ->toContain('aria-describedby="'.$field.'-error"')
            ->toContain('id="'.$field.'-error"');
    }

    // These two describe themselves through a wrapper that is always in the
    // document, so the reference cannot dangle when the field is valid.
    foreach (['email', 'message'] as $field) {
        expect($html)
            ->toContain('for="'.$field.'"')
            ->toContain('aria-describedby="'.$field.'-help"')
            ->toContain('id="'.$field.'-help"');
    }

    expect($html)->toContain('aria-invalid="true"');
});

it('rejects an empty submission, names every field, and sends nothing', function () {
    Mail::fake();

    $this->from('/contact')
        ->post('/contact', [])
        ->assertRedirect('/contact')
        ->assertSessionHasErrors(['name', 'email', 'topic', 'message']);

    Mail::assertNothingSent();
});

it('rejects a topic that is not on the allowlist', function () {
    $this->from('/contact')
        ->post('/contact', [
            'name' => 'Dana Okonkwo',
            'email' => 'dana@example.com',
            'topic' => 'anything-i-like',
            'message' => 'This message is comfortably longer than the twenty character floor.',
        ])
        ->assertSessionHasErrors('topic')
        ->assertSessionDoesntHaveErrors(['name', 'email', 'message']);
});

it('returns the sender their own input rather than a blank form', function () {
    $this->from('/contact')->post('/contact', [
        'name' => 'Dana Okonkwo',
        'email' => 'not-an-email',
        'topic' => 'payouts',
        'message' => 'Too short',
    ]);

    $html = $this->get('/contact')->assertOk()->getContent();

    expect($html)
        ->toContain('value="Dana Okonkwo"')
        ->toContain('value="not-an-email"')
        ->toContain('Too short</textarea>')
        // The topic they picked survives the round trip too.
        ->toContain('value="payouts" selected');
});

it('sends the message and redirects with a status', function () {
    Mail::fake();

    $this->post('/contact', [
        'name' => 'Dana Okonkwo',
        'email' => 'dana@example.com',
        'topic' => 'supply',
        'message' => 'We run a network with 400 live offers and a subid macro on every tracking URL.',
    ])
        ->assertRedirect('/contact')
        ->assertSessionHas('status');

    Mail::assertSent(ContactMessage::class, function (ContactMessage $mail): bool {
        $envelope = $mail->envelope();

        return $mail->hasTo((string) config('mail.from.address'))
            && $envelope->replyTo[0]->address === 'dana@example.com'
            && $mail->topic === ContactController::topics()['supply']
            && str_contains($mail->body, 'subid macro');
    });
});

it('keeps everything the sender typed out of the subject line', function () {
    Mail::fake();

    $this->post('/contact', [
        'name' => "Dana\r\nBcc: someone@example.com",
        'email' => 'dana@example.com',
        'topic' => 'other',
        'message' => 'The name field above is the only header-adjacent free text on this form.',
    ]);

    Mail::assertSent(ContactMessage::class, fn (ContactMessage $mail): bool => $mail->envelope()->subject === 'Contact form — '.ContactController::topics()['other']);
});

it('preselects the topic a deep link asked for', function () {
    $this->get('/contact?topic=supply')
        ->assertOk()
        ->assertSee('value="supply" selected', escape: false);
});

it('offers a real option for every topic the site deep-links to', function () {
    // /advertise and /status link into the form with ?topic=. A renamed key
    // would leave those links silently landing on "Choose one".
    expect(ContactController::topics())
        ->toHaveKey('supply')
        ->toHaveKey('technical');
});
