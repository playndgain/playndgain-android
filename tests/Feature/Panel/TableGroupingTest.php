<?php

use App\Enums\ConversionStatus;
use App\Enums\IncomingPostbackResult;
use App\Enums\LedgerEntryType;
use App\Filament\Admin\Resources\Conversions\Pages\ListConversions;
use App\Filament\Admin\Resources\IncomingPostbacks\Pages\ListIncomingPostbacks;
use App\Filament\Admin\Resources\LedgerEntries\Pages\ListLedgerEntries;
use App\Filament\Admin\Resources\Publishers\Pages\ViewPublisher;
use App\Filament\Admin\Resources\Publishers\RelationManagers\ConversionsRelationManager;
use App\Models\App;
use App\Models\Conversion;
use App\Models\IncomingPostback;
use App\Models\LedgerEntry;
use App\Models\Publisher;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Config;
use Livewire\Attributes\Url;

/**
 * Grouping, per-group sums and the date presets — docs/07 §6, §8.
 *
 * "Which publisher is charging back and for how much" was an export and a
 * spreadsheet. These three tables answer it on screen now, so the parts that
 * can silently stop working — a group keyed on a relationship column, a sum
 * over a derived column, a preset that resolves to the wrong window — are
 * worth holding down.
 */
beforeEach(function () {
    Config::set('playndgain.require_admin_2fa', false);
    Filament::setCurrentPanel('admin');

    $this->actingAs(User::factory()->admin()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['aaaa-bbbb'],
    ]));
});

/** A publisher with two reversed conversions and the chargebacks they wrote. */
function chargingBackPublisher(string $name, float ...$payouts): Publisher
{
    $publisher = Publisher::factory()->create(['company_name' => $name]);
    $app = App::factory()->create(['publisher_id' => $publisher->id]);

    foreach ($payouts as $payout) {
        $conversion = Conversion::factory()->create([
            'app_id' => $app->id,
            'publisher_id' => $publisher->id,
            'payout' => $payout,
            'status' => ConversionStatus::Reversed,
        ]);

        LedgerEntry::createChargeback($conversion);
    }

    return $publisher;
}

function postbackLog(string $reason): IncomingPostback
{
    return IncomingPostback::create([
        'driver' => 'fake',
        'method' => 'GET',
        'query' => [],
        'ip' => '127.0.0.1',
        'result' => IncomingPostbackResult::Rejected,
        'reject_reason' => $reason,
        'created_at' => now(),
    ]);
}

it('groups conversions by publisher and totals their payouts', function () {
    chargingBackPublisher('Chargeback Central', 4.00, 6.50);

    Livewire::test(ListConversions::class)
        ->filterTable('status', ConversionStatus::Reversed->value)
        ->set('tableGrouping', 'publisher.company_name')
        ->assertSuccessful()
        ->assertSee('Chargeback Central')
        ->assertSee('$10.50');
});

it('groups the ledger by publisher and totals the chargebacks', function () {
    chargingBackPublisher('Chargeback Central', 4.00, 6.50);

    Livewire::test(ListLedgerEntries::class)
        ->filterTable('type', LedgerEntryType::Chargeback->value)
        ->set('tableGrouping', 'publisher.company_name')
        ->assertSuccessful()
        // The sum is the whole point of grouping this table.
        ->assertSee('-$10.50');
});

it('groups incoming postbacks by reject reason', function () {
    postbackLog('network is not active');
    postbackLog('source ip not allowed');

    Livewire::test(ListIncomingPostbacks::class)
        ->set('tableGrouping', 'reject_reason')
        ->assertSuccessful()
        ->assertSee('network is not active')
        ->assertSee('source ip not allowed');
});

it('reads a date preset as a window rather than as two blank pickers', function () {
    $today = Conversion::factory()->create(['created_at' => now()]);
    $lastWeek = Conversion::factory()->create(['created_at' => now()->subDays(9)]);

    Livewire::test(ListConversions::class)
        ->filterTable('created', ['preset' => 'today'])
        ->assertCanSeeTableRecords([$today])
        ->assertCanNotSeeTableRecords([$lastWeek]);

    Livewire::test(ListConversions::class)
        ->filterTable('created', ['preset' => '30d'])
        ->assertCanSeeTableRecords([$today, $lastWeek]);
});

it('lets a preset override a range left behind in the pickers', function () {
    $today = Conversion::factory()->create(['created_at' => now()]);
    $old = Conversion::factory()->create(['created_at' => now()->subYear()]);

    // Both set. Intersecting them would show nothing at all, which reads as
    // "no conversions today" rather than as a filter arguing with itself.
    Livewire::test(ListConversions::class)
        ->filterTable('created', [
            'preset' => 'today',
            'from' => now()->subYears(2)->toDateString(),
            'until' => now()->subYear()->toDateString(),
        ])
        ->assertCanSeeTableRecords([$today])
        ->assertCanNotSeeTableRecords([$old]);
});

it('keeps the custom pickers working when no preset is chosen', function () {
    $inside = Conversion::factory()->create(['created_at' => now()->subDays(3)]);
    $outside = Conversion::factory()->create(['created_at' => now()->subDays(30)]);

    Livewire::test(ListConversions::class)
        ->filterTable('created', [
            'from' => now()->subDays(5)->toDateString(),
            'until' => now()->toDateString(),
        ])
        ->assertCanSeeTableRecords([$inside])
        ->assertCanNotSeeTableRecords([$outside]);
});

it('sums only the parent publisher in the relation manager', function () {
    $mine = chargingBackPublisher('Chargeback Central', 4.00, 6.50);
    chargingBackPublisher('Somebody Else', 100.00);

    // The same columns render inside a relation manager, where the summary is
    // computed from the relationship query. A summary that reached past it
    // would print the platform's totals on one publisher's page.
    Livewire::test(ConversionsRelationManager::class, [
        'ownerRecord' => $mine,
        'pageClass' => ViewPublisher::class,
    ])
        ->assertSuccessful()
        ->assertSee('$10.50')
        ->assertDontSee('$110.50');
});

it('puts the filters and the grouping in the query string', function (string $page) {
    // Without the attribute a filtered view cannot be linked to, and Livewire
    // gives no error when it is missing — the URL is simply never written.
    foreach (['tableFilters', 'tableGrouping'] as $property) {
        $attributes = (new ReflectionProperty($page, $property))->getAttributes(Url::class);

        expect($attributes)->not->toBeEmpty("{$page}::\${$property} is not URL-persisted");
    }
})->with([
    ListConversions::class,
    ListLedgerEntries::class,
    ListIncomingPostbacks::class,
]);
