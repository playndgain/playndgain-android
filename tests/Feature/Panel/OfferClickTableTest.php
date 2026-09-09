<?php

use App\Models\App;
use App\Models\Publisher;
use App\Models\User;
use Database\Factories\OfferClickFactory;
use Illuminate\Support\Facades\Config;

it('copies the full click id, not the truncated cell', function () {
    Config::set('playndgain.require_admin_2fa', false);

    $click = OfferClickFactory::new()->create([
        'click_id' => '9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f',
    ]);

    $this->actingAs(User::factory()->admin()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['aaaa-bbbb'],
    ]));

    $html = $this->get('/admin/offer-clicks')->assertSuccessful()->getContent();

    expect($html)
        ->toContain("writeText('{$click->click_id}')")
        ->toContain('9f8c1d2e-4a5b-...')
        ->not->toContain("writeText('9f8c1d2e-4a5b...')");
});

it('copies the full click id on the publisher clicks table', function () {
    $publisher = Publisher::factory()->create();
    $app = App::factory()->create(['publisher_id' => $publisher->id]);
    $click = OfferClickFactory::new()->create([
        'app_id' => $app->id,
        'publisher_id' => $publisher->id,
        'click_id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
    ]);

    $this->actingAs($publisher->user);

    $html = $this->get('/dashboard/offer-clicks')->assertSuccessful()->getContent();

    expect($html)
        ->toContain("writeText('{$click->click_id}')")
        ->not->toContain("writeText('aaaaaaaa-bbbb...')");
});
