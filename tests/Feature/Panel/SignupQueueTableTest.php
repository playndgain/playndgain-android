<?php

use App\Enums\PublisherStatus;
use App\Models\Publisher;
use App\Models\User;
use Illuminate\Support\Facades\Config;

function queueAdmin(): User
{
    Config::set('playndgain.require_admin_2fa', false);

    return User::factory()->admin()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['aaaa-bbbb'],
    ]);
}

it('renders an applicant with the queue chrome, not a danger badge', function () {
    $applicant = Publisher::factory()->pending()->create([
        'company_name' => 'Iberia Media',
        'country' => 'PT',
        'traffic_source' => 'Rewarded Android games in Portugal',
    ]);
    $applicant->user->update([
        'name' => 'Ana Costa',
        'email' => 'ana@iberia.test',
    ]);

    $this->actingAs(queueAdmin());

    $html = $this->get('/admin/signup-queue')->assertSuccessful()->getContent();

    expect($html)
        ->toContain('Ana Costa')
        ->toContain('ana@iberia.test')
        ->toContain('Iberia Media')
        ->toContain('#'.$applicant->pub_id)
        ->toContain('flags/pt.svg')
        ->toContain('Portugal')
        ->toContain('Rewarded Android games in Portugal')
        ->toContain('ad-status--clear')
        ->toContain('Clear')
        ->not->toContain('ad-status--hit')
        ->not->toContain('ad-row--pending')
        ->not->toContain('never signed in');
});

it('flags a plus-tagged email against a banned account', function () {
    $banned = Publisher::factory()->create(['status' => PublisherStatus::Banned]);
    $banned->user->update(['email' => 'me@gmail.com']);

    $applicant = Publisher::factory()->pending()->create();
    $applicant->user->update(['email' => 'me+queue@gmail.com']);

    $this->actingAs(queueAdmin());

    $this->get('/admin/signup-queue')
        ->assertSuccessful()
        ->assertSee('ad-status--hit', escape: false)
        ->assertSee('Email')
        ->assertSee('ad-row--pending', escape: false)
        ->assertDontSee('Email + IP');
});

it('flags a shared IP, and both signals together', function () {
    $banned = Publisher::factory()->create(['status' => PublisherStatus::Banned]);
    $banned->user->update(['email' => 'banned@vault.test']);
    $banned->user->forceFill(['last_login_ip' => '203.0.113.9'])->save();

    $ipOnly = Publisher::factory()->pending()->create();
    $ipOnly->user->update(['email' => 'fresh@vault.test']);
    $ipOnly->user->forceFill(['last_login_ip' => '203.0.113.9'])->save();

    $both = Publisher::factory()->pending()->create();
    $both->user->update(['email' => 'banned+alt@vault.test']);
    $both->user->forceFill(['last_login_ip' => '203.0.113.9'])->save();

    $this->actingAs(queueAdmin());

    $html = $this->get('/admin/signup-queue')->assertSuccessful()->getContent();

    expect($html)
        ->toContain('ad-status--hit')
        ->toContain('Email + IP')
        ->toContain('ad-status__label">IP');
});

it('does not treat a missing IP as a match', function () {
    $banned = Publisher::factory()->create(['status' => PublisherStatus::Banned]);
    $banned->user->update(['email' => 'other@vault.test']);
    $banned->user->forceFill(['last_login_ip' => null])->save();

    Publisher::factory()->pending()->create();

    $this->actingAs(queueAdmin());

    $this->get('/admin/signup-queue')
        ->assertSuccessful()
        ->assertSee('ad-status--clear', escape: false)
        ->assertDontSee('ad-status--hit', escape: false);
});
