<?php

use App\Enums\PublisherStatus;
use App\Models\Publisher;
use App\Models\User;

/**
 * Empty states are invisible the moment a table has rows, so nothing else in
 * the suite would notice them disappearing. Both admin tables shipped with no
 * empty state at all, and the publisher one promised a create step it gave the
 * reader no way to take.
 */
function adminForEmptyState(): User
{
    // phpunit.xml pins the admin 2FA requirement on; the panel reads it at boot.
    return User::factory()->admin()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['aaaa-bbbb'],
    ]);
}

it('tells an admin where rows come from when there are none', function (string $path, string $heading, string $description) {
    $this->actingAs(adminForEmptyState())
        ->get($path)
        ->assertOk()
        ->assertSee($heading)
        ->assertSee($description);
})->with([
    ['/admin/apps', 'No apps yet', 'Apps appear here once a publisher creates a placement in their panel.'],
    ['/admin/publishers', 'No publishers yet', 'Publishers appear here once someone registers'],
]);

it('offers the create action inside the publisher empty state, not only in the header', function () {
    $publisher = Publisher::factory()->create(['status' => PublisherStatus::Approved]);

    $html = $this->actingAs($publisher->user)->get('/dashboard/apps')->assertOk()->getContent();

    expect($html)->toContain('No apps yet');

    // The heading says "Create one to…", so the empty state has to carry the
    // action. Two occurrences: the header button and the empty-state one.
    expect(substr_count($html, 'New app'))->toBeGreaterThan(1);
});
