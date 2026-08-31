<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

class SettingsSeeder extends Seeder
{
    /**
     * EU-27 + UK + the three EEA states. Wall traffic from these needs the
     * consent gate before anything is stored against the end user.
     */
    private const CONSENT_REQUIRED_COUNTRIES = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR',
        'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK',
        'SI', 'ES', 'SE',
        'GB',
        'IS', 'LI', 'NO',
    ];

    public function run(): void
    {
        // platform_name, support_email and INVOICE_* are not seeded. They
        // follow .env unless an admin fills the override on the settings page.

        $settings = [
            'default_rev_share' => '70.00',
            'default_hold_days' => '30',
            'default_min_payout' => '20.00',
            'signups_enabled' => '1',
            'auto_approve_publishers' => '0',
            'wall_offers_limit' => '100',
            'consent_required_countries' => json_encode(self::CONSENT_REQUIRED_COUNTRIES),
        ];

        foreach ($settings as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        Cache::forget(Setting::CACHE_KEY);
    }
}
