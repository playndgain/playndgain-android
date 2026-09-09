<?php

use App\Models\User;
use EslamRedaDiv\TimezoneDetector\Facades\TimezoneDetector;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Facades\Config;

/**
 * Admin dates are stored in UTC and shown in the browser zone the detector
 * writes into the session. The assertion that matters is on Filament's
 * timezone manager, the same way the publisher panel test pins docs/08 §14.
 */
it('renders admin panel dates in the detected browser timezone', function () {
    Config::set('playndgain.require_admin_2fa', false);
    Filament::setCurrentPanel('admin');

    $this->actingAs(User::factory()->admin()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['aaaa-bbbb'],
    ]));

    $this->withHeader('X-Timezone', 'Europe/Berlin')
        ->get('/admin')
        ->assertSuccessful();

    expect(TimezoneDetector::getUserTimezone())->toBe('Europe/Berlin')
        ->and(FilamentTimezone::get())->toBe('Europe/Berlin');
});
