<?php

use App\Filament\Support\InitialsAvatarProvider;
use App\Filament\Support\InitialsChip;
use App\Models\Network;
use App\Models\Offer;
use App\Models\User;

/**
 * Filament's stock provider points every avatar at ui-avatars.com with the
 * account holder's name in the query string. Nothing in a panel screen makes
 * that visible — the chip renders either way — so the regression this guards
 * is silent: any panel that loses `defaultAvatarProvider` starts calling a
 * third party with our users' names again.
 */
it('draws the account chip locally instead of calling a third party', function (string $panel) {
    // phpunit.xml pins the admin 2FA requirement on, and the panel reads it at
    // boot — so the account needs a real secret or /admin bounces to set-up
    // before it ever renders a sidebar (PanelSmokeTest §adminWithTwoFactor).
    $user = User::factory()->admin()->create([
        'name' => 'Ziad Hawy',
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['aaaa-bbbb'],
    ]);

    $html = $this->actingAs($user)->get("/{$panel}")->assertOk()->getContent();

    expect($html)->not->toContain('ui-avatars.com')
        ->and($html)->toContain('data:image/svg+xml;base64,');
})->with(['admin', 'dashboard']);

it('carries the first letter of the name on the amber ground', function () {
    $svg = base64_decode(explode(',', (new InitialsAvatarProvider)->get(
        new User(['name' => 'ziad hawy'])
    ))[1]);

    // `on-amber` on `amber-400` — 12:1, the pair design.md §8 already states.
    expect($svg)->toContain('fill="#FFB020"')
        ->and($svg)->toContain('fill="#1A1000"')
        ->and($svg)->toContain('>Z</text>');
});

it('leaves a bare chip rather than a broken glyph for an empty name', function () {
    $svg = base64_decode(explode(',', (new InitialsAvatarProvider)->get(
        new User(['name' => '  '])
    ))[1]);

    expect($svg)->toContain('></text>');
});

/**
 * The icon columns had no fallback: an offer or network with no picture left a
 * hole where the 32px circle goes, and one whose URL had stopped resolving left
 * the browser's broken-image glyph. Both now fall back to the same chip.
 */
it('falls back to a chip drawn from the offer, never the network', function () {
    $network = Network::factory()->create(['name' => 'Zebranet']);
    $offer = Offer::factory()->create([
        'network_id' => $network->id,
        'name' => 'Alpha Quest',
        'icon_url' => null,
    ]);

    $chip = base64_decode(explode(',', InitialsChip::for($offer->name))[1]);

    // 'A' for the offer. A 'Z' here would mean the row had leaked the network
    // to a publisher (AGENTS.md §1).
    expect($chip)->toContain('>A</text>')->not->toContain('>Z</text>');
});

it('renders that fallback in the admin offer list', function () {
    Offer::factory()->create(['name' => 'Alpha Quest', 'icon_url' => null]);

    $admin = User::factory()->admin()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['aaaa-bbbb'],
    ]);

    // Proves the closure form of `defaultImageUrl` is actually evaluated with
    // the record — a chip built for the wrong argument would still be a valid
    // data URI and would pass a unit test.
    $this->actingAs($admin)->get('/admin/offers')
        ->assertOk()
        ->assertSee(InitialsChip::for('Alpha Quest'), escape: false);
});
