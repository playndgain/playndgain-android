<?php

use App\Models\Network;
use App\Models\User;
use Database\Seeders\NetworkSeeder;
use Illuminate\Support\Facades\Config;

/**
 * The logo column, asserted end to end.
 *
 * `networks.logo_path` is a path under `public/`, and a Filament ImageColumn
 * handed a bare path resolves it against the configured storage disk instead —
 * it would ask for `/storage/img/networks/notik.png`, 404, and render a broken
 * image in every row. Nothing else in the suite looks at an `img` src, so the
 * failure would have been invisible until someone opened the page.
 */
beforeEach(function () {
    Config::set('advault.require_admin_2fa', false);

    $this->actingAs(User::factory()->admin()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['aaaa-bbbb'],
    ]));
});

it('renders each seeded logo at a url that resolves to a real file', function () {
    $this->seed(NetworkSeeder::class);

    $html = $this->get('/admin/networks')->assertSuccessful()->getContent();

    foreach (Network::query()->get() as $network) {
        $path = (string) $network->logo_path;

        expect($html)->toContain(asset($path))
            // Not the storage disk, which is where an unresolved ImageColumn
            // would have pointed.
            ->and($html)->not->toContain('/storage/'.$path)
            ->and(public_path($path))->toBeFile();
    }
});
