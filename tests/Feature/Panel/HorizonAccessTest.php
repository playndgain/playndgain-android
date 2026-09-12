<?php

use App\Models\Publisher;
use App\Models\User;
use Filament\Facades\Filament;

it('sends a guest to the admin login instead of a bare 403', function () {
    $this->get('/horizon')->assertRedirect('/admin/login');
});

it('refuses a publisher the horizon dashboard', function () {
    $this->actingAs(Publisher::factory()->create()->user)
        ->get('/horizon')
        ->assertForbidden();
});

it('lets an admin open horizon', function () {
    config()->set('playndgain.require_admin_2fa', false);
    Filament::setCurrentPanel('admin');

    $this->actingAs(User::factory()->admin()->create())
        ->get('/horizon')
        ->assertOk();
});
