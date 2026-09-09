<?php

use App\Http\Controllers\NewsletterController;
use App\Models\NewsletterSubscriber;
use Illuminate\Support\Facades\URL;

/**
 * The footer's mailing list is a plain POST to this app: it has to work with
 * the bundle blocked, thank a repeat submit rather than scold it, and hand a
 * script that filled the honeypot the same thank-you and no row.
 *
 * The row it writes is a consent record — where the address was given and
 * roughly where from — and it comes with a signed way off the list.
 */
it('subscribes an address and stores it lower-cased', function () {
    $this->from('http://localhost/')
        ->post(route('newsletter.subscribe'), ['email' => 'Dana@Example.com'])
        ->assertRedirect()
        ->assertSessionHas('newsletter', 'You are on the list.');

    expect(NewsletterSubscriber::query()->count())->toBe(1)
        ->and(NewsletterSubscriber::query()->first())
        ->email->toBe('dana@example.com')
        ->source->toBe('/')
        ->unsubscribed_at->toBeNull();
});

it('records the page the address was given on, not a constant', function () {
    // The footer renders site-wide, so a docs reader who subscribes is not a
    // landing-page subscriber.
    $this->from('http://localhost/docs/quick-start')
        ->post(route('newsletter.subscribe'), ['email' => 'dana@example.com']);

    expect(NewsletterSubscriber::query()->first()->source)->toBe('/docs/quick-start');
});

it('does not store a referer from another host', function () {
    $this->withHeader('referer', 'https://elsewhere.example/whatever')
        ->post(route('newsletter.subscribe'), ['email' => 'dana@example.com']);

    expect(NewsletterSubscriber::query()->first()->source)->toBe('direct');
});

it('stores a masked address rather than the whole one', function () {
    $this->from('http://localhost/')
        ->post(route('newsletter.subscribe'), ['email' => 'dana@example.com'], ['REMOTE_ADDR' => '203.0.113.77']);

    // IpAnonymizer keeps the network part and drops the host part.
    expect(NewsletterSubscriber::query()->first()->ip)->toBe('203.0.113.0');
});

it('treats a second submit as the same person pressing the button twice', function () {
    $this->post(route('newsletter.subscribe'), ['email' => 'dana@example.com'])->assertRedirect();
    $this->post(route('newsletter.subscribe'), ['email' => 'DANA@example.com'])
        ->assertRedirect()
        ->assertSessionHas('newsletter');

    expect(NewsletterSubscriber::query()->count())->toBe(1);
});

it('returns a validation error for an address that is not one', function () {
    $this->from('/')
        ->post(route('newsletter.subscribe'), ['email' => 'not an address'])
        ->assertRedirect('/')
        ->assertSessionHasErrorsIn('newsletter', ['email']);

    expect(NewsletterSubscriber::query()->count())->toBe(0);
});

it('thanks a filled honeypot and stores nothing', function () {
    $this->from('/')
        ->post(route('newsletter.subscribe'), ['email' => 'bot@example.com', 'company' => 'Acme'])
        ->assertRedirect()
        ->assertSessionHas('newsletter');

    expect(NewsletterSubscriber::query()->count())->toBe(0);
});

it('renders the form on the landing page with the honeypot hidden', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('action="'.route('newsletter.subscribe').'"', escape: false)
        ->assertSee('name="company"', escape: false)
        ->assertSee('Stay in the loop');
});

it('tells the subscriber they are on the list', function () {
    // The whole point of the press. MessageBag::first() returns '' rather than
    // null for an empty bag, so the footer's error branch used to fire on every
    // render and this message was unreachable: the row was written and the page
    // came back looking untouched.
    $this->from('http://localhost/')
        ->post(route('newsletter.subscribe'), ['email' => 'dana@example.com']);

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('You are on the list')
        ->toContain('role="status"');
});

it('leaves the footer form unmarked when nothing has gone wrong', function () {
    $html = $this->get('/')->assertOk()->getContent();

    $footer = mb_substr($html, (int) mb_strpos($html, 'id="newsletter"'));

    // An empty error paragraph and a permanent aria-invalid are both a11y
    // failures on a form nobody has touched yet.
    expect($footer)->not->toContain('id="newsletter-error"')
        ->not->toContain('aria-invalid');
});

it('shows the reason an address was refused', function () {
    $this->from('http://localhost/')
        ->post(route('newsletter.subscribe'), ['email' => 'not an address']);

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('id="newsletter-error"')
        ->toContain('aria-invalid="true"')
        // Their typing comes back with them rather than an empty box.
        ->toContain('value="not an address"');
});

it('shows the newsletter error beside the form, not the contact form\'s', function () {
    $this->from('/')
        ->post(route('newsletter.subscribe'), ['email' => 'nope'])
        ->assertSessionHasErrorsIn('newsletter');

    // The named bag keeps the footer's mistake out of the contact page and
    // the contact page's mistakes out of the footer.
    $this->post(route('contact.send'), ['name' => '', 'email' => 'x', 'topic' => '', 'message' => ''])
        ->assertSessionHasErrors(['email'])
        ->assertSessionDoesntHaveErrors(['email'], errorBag: 'newsletter');
});

// ── The background arm ────────────────────────────────────────────────────

it('answers a background post with the confirmation instead of a redirect', function () {
    // site.js posts this so the page never reloads: no navigation means no
    // scroll, which is the whole point of the arm.
    $this->postJson(route('newsletter.subscribe'), ['email' => 'dana@example.com'])
        ->assertOk()
        ->assertExactJson(['message' => NewsletterController::CONFIRMATION]);

    expect(NewsletterSubscriber::query()->count())->toBe(1);
});

it('hands the background arm the reason an address was refused', function () {
    $this->postJson(route('newsletter.subscribe'), ['email' => 'not an address'])
        ->assertStatus(422)
        // The shape site.js reads to fill the warn toast.
        ->assertJsonPath('errors.email.0', 'The email field must be a valid email address.');

    expect(NewsletterSubscriber::query()->count())->toBe(0);
});

it('thanks a background honeypot exactly as it thanks the form one', function () {
    $this->postJson(route('newsletter.subscribe'), ['email' => 'bot@example.com', 'company' => 'Acme'])
        ->assertOk()
        ->assertExactJson(['message' => NewsletterController::CONFIRMATION]);

    expect(NewsletterSubscriber::query()->count())->toBe(0);
});

it('keeps the whole form working with the bundle blocked', function () {
    $html = $this->get('/')->assertOk()->getContent();

    $footer = mb_substr($html, (int) mb_strpos($html, 'id="newsletter"'));

    // No fetch, no Alpine, no submit handler in the markup: a plain POST with
    // a token, which is what runs when site.js never arrives.
    expect($footer)->toContain('method="POST"')
        ->toContain('name="_token"')
        ->not->toContain('onsubmit')
        ->not->toContain('x-data');

    // And the arm that does the work is reachable without a script.
    $this->from('http://localhost/')
        ->post(route('newsletter.subscribe'), ['email' => 'nojs@example.com'])
        ->assertRedirect();

    expect(NewsletterSubscriber::query()->where('email', 'nojs@example.com')->exists())->toBeTrue();
});

// ── Off the list ──────────────────────────────────────────────────────────

it('takes an address off the list from a signed link with no session', function () {
    $subscriber = NewsletterSubscriber::query()->create(['email' => 'dana@example.com']);

    $this->get($subscriber->unsubscribeUrl())
        ->assertOk()
        ->assertSee('You are off the list')
        ->assertSee('dana@example.com');

    expect($subscriber->fresh()->unsubscribed_at)->not->toBeNull();
});

it('keeps the row when someone unsubscribes', function () {
    // The date is what proves they asked. A deleted row would take the next
    // submit of the same address as a fresh sign-up.
    $subscriber = NewsletterSubscriber::query()->create(['email' => 'dana@example.com']);

    $this->get($subscriber->unsubscribeUrl());

    expect(NewsletterSubscriber::query()->count())->toBe(1);
});

it('says the same thing on a second click rather than reporting an error', function () {
    $subscriber = NewsletterSubscriber::query()->create(['email' => 'dana@example.com']);
    $url = $subscriber->unsubscribeUrl();

    $this->get($url)->assertOk();
    $first = $subscriber->fresh()->unsubscribed_at;

    $this->get($url)->assertOk()->assertSee('You are off the list');

    // Idempotent: the second visit does not move the date it already has.
    expect($subscriber->fresh()->unsubscribed_at->eq($first))->toBeTrue();
});

it('refuses an unsubscribe link that is not signed', function () {
    $subscriber = NewsletterSubscriber::query()->create(['email' => 'dana@example.com']);

    // Counting up the ids must not opt somebody else out.
    $this->get(URL::route('newsletter.unsubscribe', ['subscriber' => $subscriber->getKey()]))
        ->assertForbidden();

    expect($subscriber->fresh()->isSubscribed())->toBeTrue();
});

it('puts a returning address back on the list rather than arguing', function () {
    $subscriber = NewsletterSubscriber::query()->create([
        'email' => 'dana@example.com',
        'source' => '/advertise',
        'unsubscribed_at' => now()->subMonth(),
    ]);

    $this->from('http://localhost/docs/quick-start')
        ->post(route('newsletter.subscribe'), ['email' => 'dana@example.com'])
        ->assertSessionHas('newsletter');

    expect(NewsletterSubscriber::query()->count())->toBe(1)
        ->and($subscriber->fresh())
        ->unsubscribed_at->toBeNull()
        // Coming back is a fresh consent, so the record is taken again.
        ->source->toBe('/docs/quick-start');
});

it('does not rewrite the consent record when someone presses the button twice', function () {
    $this->from('http://localhost/docs/quick-start')
        ->post(route('newsletter.subscribe'), ['email' => 'dana@example.com'], ['REMOTE_ADDR' => '203.0.113.77']);

    // Same person, a later page, still on the list. Overwriting `source` here
    // would leave `created_at` describing a page they were never on.
    $this->from('http://localhost/')
        ->post(route('newsletter.subscribe'), ['email' => 'dana@example.com'], ['REMOTE_ADDR' => '198.51.100.9']);

    expect(NewsletterSubscriber::query()->count())->toBe(1)
        ->and(NewsletterSubscriber::query()->first())
        ->source->toBe('/docs/quick-start')
        ->ip->toBe('203.0.113.0');
});

it('keeps the unsubscribe page out of the index', function () {
    $subscriber = NewsletterSubscriber::query()->create(['email' => 'dana@example.com']);

    $this->get($subscriber->unsubscribeUrl())
        ->assertSee('name="robots" content="noindex, nofollow"', escape: false);
});
