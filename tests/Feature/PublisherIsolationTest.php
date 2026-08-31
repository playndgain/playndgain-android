<?php

use App\Models\App;
use App\Models\Conversion;
use App\Models\Publisher;
use App\Models\User;

/**
 * The cross-tenant leak test.
 *
 * This is the test the whole publisher panel rests on: publisher A must never
 * read publisher B's rows. It asserts the policy and the global scope, not the
 * UI, because the UI is not what will leak — a resource added in phase 5 that
 * forgets a `where` is.
 */
function twoPublishersWithData(): array
{
    $a = Publisher::factory()->create();
    $b = Publisher::factory()->create();

    App::factory()->count(2)->create(['publisher_id' => $a->id]);
    App::factory()->count(3)->create(['publisher_id' => $b->id]);

    return [$a, $b];
}

it('scopes apps to the authenticated publisher', function () {
    [$a, $b] = twoPublishersWithData();

    $this->actingAs($a->user);
    expect(App::query()->pluck('publisher_id')->unique()->all())->toBe([$a->id]);
    expect(App::query()->count())->toBe(2);

    $this->actingAs($b->user);
    expect(App::query()->pluck('publisher_id')->unique()->all())->toBe([$b->id]);
    expect(App::query()->count())->toBe(3);
});

it('cannot reach another publisher\'s app by id', function () {
    [$a, $b] = twoPublishersWithData();

    $othersApp = App::withoutGlobalScopes()->where('publisher_id', $b->id)->firstOrFail();

    $this->actingAs($a->user);

    expect(App::query()->find($othersApp->id))->toBeNull();
});

it('scopes conversions to the authenticated publisher', function () {
    [$a, $b] = twoPublishersWithData();

    $appA = App::withoutGlobalScopes()->where('publisher_id', $a->id)->firstOrFail();
    $appB = App::withoutGlobalScopes()->where('publisher_id', $b->id)->firstOrFail();

    Conversion::factory()->create(['app_id' => $appA->id, 'publisher_id' => $a->id]);
    Conversion::factory()->count(2)->create(['app_id' => $appB->id, 'publisher_id' => $b->id]);

    $this->actingAs($a->user);

    expect(Conversion::query()->count())->toBe(1);
    expect(Conversion::query()->sole()->publisher_id)->toBe($a->id);
});

it('denies a publisher any policy action on another publisher\'s row', function () {
    [$a, $b] = twoPublishersWithData();

    $othersApp = App::withoutGlobalScopes()->where('publisher_id', $b->id)->firstOrFail();

    expect($a->user->can('view', $othersApp))->toBeFalse()
        ->and($a->user->can('update', $othersApp))->toBeFalse()
        ->and($a->user->can('delete', $othersApp))->toBeFalse();
});

it('lets a publisher act on their own row', function () {
    [$a] = twoPublishersWithData();

    $ownApp = App::withoutGlobalScopes()->where('publisher_id', $a->id)->firstOrFail();

    expect($a->user->can('view', $ownApp))->toBeTrue()
        ->and($a->user->can('update', $ownApp))->toBeTrue();
});

it('does not scope admins', function () {
    twoPublishersWithData();

    $this->actingAs(User::factory()->admin()->create());

    expect(App::query()->count())->toBe(5);
});

it('shows a publisher user with no publisher row nothing at all', function () {
    twoPublishersWithData();

    // A signup that has not been given a publisher record yet must see zero
    // rows, not every row.
    $this->actingAs(User::factory()->create());

    expect(App::query()->count())->toBe(0);
});

it('leaves unauthenticated contexts unscoped so queue jobs and the wall still work', function () {
    twoPublishersWithData();

    expect(App::query()->count())->toBe(5);
});
