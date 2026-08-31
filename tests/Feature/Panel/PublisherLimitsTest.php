<?php

use App\Enums\BlacklistType;
use App\Enums\OutgoingPostbackStatus;
use App\Filament\Publisher\Resources\PostbackReports\Pages\ListPostbackReports;
use App\Filament\Publisher\Support\PublisherTestPostbackAction;
use App\Jobs\DispatchPublisherPostback;
use App\Models\App;
use App\Models\Blacklist;
use App\Models\Conversion;
use App\Models\OutgoingPostback;
use App\Models\Publisher;
use App\Networks\OfferCacheFlusher;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

/**
 * The two rules from docs/08 §16 that protect other people's systems: the
 * re-send limiter, and a cache flush that touches one app and nobody else's.
 */
it('rate-limits test postbacks per app', function () {
    $publisher = Publisher::factory()->create();
    $app = App::factory()->create(['publisher_id' => $publisher->id]);

    $key = "test-postback:{$app->id}";

    for ($i = 0; $i < PublisherTestPostbackAction::PER_MINUTE; $i++) {
        expect(RateLimiter::tooManyAttempts($key, PublisherTestPostbackAction::PER_MINUTE))->toBeFalse();

        RateLimiter::hit($key);
    }

    // Eleven in a minute is not debugging, it is a load generator pointed at
    // someone else's endpoint.
    expect(RateLimiter::tooManyAttempts($key, PublisherTestPostbackAction::PER_MINUTE))->toBeTrue()
        ->and(RateLimiter::availableIn($key))->toBeGreaterThan(0);
});

/**
 * The re-send limiter, exercised through the action that owns it.
 *
 * Its key is `postback-resend:{app}` — a different one from the test-postback
 * limiter above — so counting hits on a key the test picked itself proves
 * nothing about the button a publisher can hold down. This drives the real
 * row action and then reads the key the resource decided to use.
 */
it('rate-limits re-sends through the action that fires them', function () {
    Queue::fake();
    Filament::setCurrentPanel('publisher');

    $publisher = Publisher::factory()->create();
    $app = App::factory()->create([
        'publisher_id' => $publisher->id,
        'postback_url' => 'https://publisher.example/cb',
    ]);

    $conversion = Conversion::factory()->create([
        'app_id' => $app->id,
        'publisher_id' => $publisher->id,
    ]);

    $failed = OutgoingPostback::create([
        'conversion_id' => $conversion->id,
        'app_id' => $app->id,
        'kind' => 'credit',
        'url' => 'https://publisher.example/cb?sig=REDACTED',
        'method' => 'GET',
        'attempt' => 1,
        'status' => OutgoingPostbackStatus::Failed,
        'http_status' => 500,
        'created_at' => now(),
    ]);

    $this->actingAs($publisher->user);

    Livewire::test(ListPostbackReports::class)
        ->callTableAction('resend', $failed)
        ->assertHasNoTableActionErrors();

    Queue::assertPushed(DispatchPublisherPostback::class, 1);

    // The action hit its own key, not the one the test-postback button uses.
    expect(RateLimiter::attempts("postback-resend:{$app->id}"))->toBe(1)
        ->and(RateLimiter::attempts("test-postback:{$app->id}"))->toBe(0);

    // Fill the rest of the minute, then ask for one more.
    for ($i = 1; $i < 10; $i++) {
        RateLimiter::hit("postback-resend:{$app->id}");
    }

    Livewire::test(ListPostbackReports::class)
        ->callTableAction('resend', $failed)
        ->assertNotified('Slow down');

    // Eleven in a minute is a load generator pointed at someone else's
    // endpoint, so the eleventh queues nothing.
    Queue::assertPushed(DispatchPublisherPostback::class, 1);
});

it('offers no re-send on a postback that already landed', function () {
    Filament::setCurrentPanel('publisher');

    $publisher = Publisher::factory()->create();
    $app = App::factory()->create(['publisher_id' => $publisher->id]);

    $conversion = Conversion::factory()->create([
        'app_id' => $app->id,
        'publisher_id' => $publisher->id,
    ]);

    $delivered = OutgoingPostback::create([
        'conversion_id' => $conversion->id,
        'app_id' => $app->id,
        'kind' => 'credit',
        'url' => 'https://publisher.example/cb?sig=REDACTED',
        'method' => 'GET',
        'attempt' => 1,
        'status' => OutgoingPostbackStatus::Success,
        'http_status' => 200,
        'created_at' => now(),
    ]);

    $this->actingAs($publisher->user);

    // Re-sending a delivered postback double-credits the publisher's own user.
    Livewire::test(ListPostbackReports::class)
        ->assertTableActionHidden('resend', $delivered);
});

it('limits each app separately', function () {
    $publisher = Publisher::factory()->create();
    $first = App::factory()->create(['publisher_id' => $publisher->id]);
    $second = App::factory()->create(['publisher_id' => $publisher->id]);

    for ($i = 0; $i < PublisherTestPostbackAction::PER_MINUTE; $i++) {
        RateLimiter::hit("test-postback:{$first->id}");
    }

    expect(RateLimiter::tooManyAttempts("test-postback:{$first->id}", PublisherTestPostbackAction::PER_MINUTE))->toBeTrue()
        ->and(RateLimiter::tooManyAttempts("test-postback:{$second->id}", PublisherTestPostbackAction::PER_MINUTE))->toBeFalse();
});

it('flushes only the affected app\'s offer cache', function () {
    $publisher = Publisher::factory()->create();
    $mine = App::factory()->create(['publisher_id' => $publisher->id]);

    $stranger = Publisher::factory()->create();
    $theirs = App::factory()->create(['publisher_id' => $stranger->id]);

    Cache::tags("app:{$mine->id}")->put('offers', ['a'], 60);
    Cache::tags("app:{$theirs->id}")->put('offers', ['b'], 60);

    OfferCacheFlusher::flushForApp($mine);

    // A publisher's blacklist edit must never clear another publisher's wall.
    expect(Cache::tags("app:{$mine->id}")->get('offers'))->toBeNull()
        ->and(Cache::tags("app:{$theirs->id}")->get('offers'))->toBe(['b']);
});

it('writes a blacklist entry scoped to one app', function () {
    $publisher = Publisher::factory()->create();
    $first = App::factory()->create(['publisher_id' => $publisher->id]);
    $second = App::factory()->create(['publisher_id' => $publisher->id]);

    $this->actingAs($publisher->user);

    Blacklist::create([
        'publisher_id' => $publisher->id,
        'app_id' => $first->id,
        'type' => BlacklistType::Offer,
        'value' => '42',
    ]);

    expect(Blacklist::query()->where('app_id', $first->id)->count())->toBe(1)
        ->and(Blacklist::query()->where('app_id', $second->id)->count())->toBe(0);
});
