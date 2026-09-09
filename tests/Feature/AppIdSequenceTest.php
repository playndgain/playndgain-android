<?php

use App\Enums\AppStatus;
use App\Models\App;
use App\Models\Publisher;

/**
 * App IDs are unique across the platform, so the next one must be computed
 * over every publisher's apps. Under the publisher scope the lookup saw only
 * the signed-in publisher's rows and re-issued an ID another publisher held.
 */
it('issues the next app ID across all publishers while signed in as one', function () {
    $first = Publisher::factory()->create();
    $second = Publisher::factory()->create();

    App::factory()->create(['publisher_id' => $first->id]);
    $latest = App::factory()->create(['publisher_id' => $second->id]);

    $this->actingAs($first->user);

    $mine = App::create([
        'publisher_id' => $first->id,
        'name' => 'Third',
        'platform' => 'web',
        'currency_name' => 'Coins',
        'currency_rate' => 1000,
        'status' => AppStatus::Pending,
    ]);

    expect($mine->app_id)->toBe($latest->app_id + 1);
});
