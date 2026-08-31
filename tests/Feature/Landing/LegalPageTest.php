<?php

/**
 * The legal pages come out of the same Markdown parser the documentation uses,
 * so what is worth asserting is not that the pipeline works — DocsPageTest
 * already covers that — but that the *content* is the product's own terms and
 * still agrees with the pages that state the same numbers.
 *
 * A rev-share, a hold or a minimum that drifts between the docs and the
 * agreement is the failure this file exists to catch.
 */
it('serves every legal page with its own real content', function (string $slug, string $heading, string $marker, string $updated) {
    $this->get("/legal/{$slug}")
        ->assertOk()
        ->assertSee($heading, escape: false)
        ->assertSee("Last updated: {$updated}")
        ->assertSee($marker, escape: false);
})->with([
    ['privacy', 'Privacy policy', 'We are not a registered IAB TCF vendor', '2026-08-16'],
    ['terms', 'Terms of service', 'AdVault is an <strong>offerwall aggregator</strong>', '2026-08-16'],
    ['publisher-agreement', 'Publisher agreement', 'The default is 70%', '2026-09-01'],
]);

it('404s a legal slug that is not one of the three', function (string $slug) {
    $this->get("/legal/{$slug}")->assertNotFound();
})->with(['refunds', 'nav', 'privacy-policy', 'about', 'cookies']);

it('keeps the payout figures identical to the payout terms documentation', function () {
    $agreement = $this->get('/legal/publisher-agreement')->getContent();

    foreach (['70%', 'start of the next calendar month', '$20'] as $figure) {
        expect($agreement)->toContain($figure);
    }

    // Same three figures, same meaning, in the page a developer reads instead.
    $docs = $this->get('/docs/payout-terms')->getContent();

    foreach (['70%', 'start of the next calendar month', '$20'] as $figure) {
        expect($docs)->toContain($figure);
    }
});

it('states what a deletion request does not erase', function () {
    // The retention basis is the one claim on the privacy page that the code
    // makes on its own — PrivacyController keeps the money rows — so the page
    // has to say so rather than imply a full erasure.
    $this->get('/legal/privacy')
        ->assertSee('Conversions and ledger entries survive it', escape: false)
        ->assertSee('/privacy/data', escape: false)
        ->assertSee('/privacy/delete', escape: false);
});

it('never renders the counsel-review note into the page', function (string $slug) {
    // The note lives in front matter, which DocParser drops. It is there for
    // whoever edits the file, and a user-visible "not reviewed by a lawyer yet"
    // is the placeholder text these pages were written to avoid.
    $this->get("/legal/{$slug}")->assertDontSee('reviewed by counsel');
})->with(['privacy', 'terms', 'publisher-agreement']);

it('renders the legal pages inside the site chrome', function () {
    $this->get('/legal/terms')
        ->assertSee('Skip to content')
        ->assertSee('id="main"', escape: false)
        // The prose stylesheet is pushed into <head> from the docs partial, so
        // the Markdown these pages share a parser with is also styled by the
        // same rules rather than by a second sheet that starts identical.
        ->assertSee('.doc-prose h2', escape: false)
        ->assertSee('AdVault');
});
