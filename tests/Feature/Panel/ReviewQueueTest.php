<?php

use App\Enums\ConversionStatus;
use App\Enums\FraudAction;
use App\Enums\FraudStage;
use App\Enums\LedgerEntryType;
use App\Filament\Admin\Pages\ReviewQueue;
use App\Models\AuditLog;
use App\Models\Conversion;
use App\Models\LedgerEntry;
use App\Models\Publisher;
use App\Models\User;
use App\Services\Money\ConversionProcessor;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

require_once __DIR__.'/../Money/MoneyFixtures.php';
require_once __DIR__.'/../Fraud/FraudFixtures.php';

/**
 * docs/12-roadmap.md, Phase 3 "Done when": *a flagged conversion appears in the
 * review queue and can be approved into a real ledger entry.*
 *
 * That sentence is the acceptance criterion for the whole phase and nothing was
 * asserting the second half of it. The conversion is produced by the real
 * pipeline — a `flag` rule firing on an inbound postback — rather than a
 * factory row with `status = pending` typed onto it, because a hand-built
 * pending conversion proves the panel works on data the platform never makes.
 */
beforeEach(function () {
    Config::set('advault.require_admin_2fa', false);
    Filament::setCurrentPanel('admin');

    fraudBoot();

    $this->admin = User::factory()->admin()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['aaaa-bbbb'],
    ]);

    $this->actingAs($this->admin);

    // A conversion held at pending by a flag rule, exactly as the inbound
    // postback path produces one. 70% of $0.13 is $0.091.
    $this->flag = function (): Conversion {
        $click = moneyFixture(publisher: ['rev_share' => 70.00]);

        fraudRule('impossible_speed', FraudStage::PostConversion, FraudAction::Flag, ['min_seconds' => 900], weight: 20);

        firePostback(postbackDto($click))->assertOk();

        $conversion = Conversion::query()->sole();

        expect($conversion->status)->toBe(ConversionStatus::Pending)
            ->and(LedgerEntry::query()->count())->toBe(0);

        return $conversion;
    };
});

it('shows a flagged conversion in the queue with the rule that held it', function () {
    Queue::fake();

    $conversion = ($this->flag)();

    // The rule key is the half that regressed: post-conversion fraud events are
    // recorded against the *click*, so a queue matching only the conversion
    // morph renders "none recorded" for every row it exists to explain.
    $this->get('/admin/review-queue')
        ->assertSuccessful()
        ->assertSee('impossible_speed')
        ->assertDontSee('none recorded');

    // Same events, listed in full on the detail page — docs/07 §6.
    $this->get("/admin/conversions/{$conversion->id}")
        ->assertSuccessful()
        ->assertSee('impossible_speed')
        ->assertSee('elapsed_seconds');
});

it('approves a flagged conversion into a real ledger entry and moves the balance', function () {
    Queue::fake();

    $conversion = ($this->flag)();

    Livewire::test(ReviewQueue::class)->callTableAction('approve', $conversion);

    $entry = LedgerEntry::query()->sole();
    $publisher = Publisher::query()->findOrFail($conversion->publisher_id);

    expect($conversion->fresh()->status)->toBe(ConversionStatus::Approved)
        ->and($entry->type)->toBe(LedgerEntryType::Earning)
        ->and($entry->amount)->toBe('0.091000')
        ->and($entry->balance_after)->toBe('0.091000')
        ->and($entry->reference_type)->toBe(Conversion::class)
        ->and($entry->reference_id)->toBe($conversion->id)
        // The entry is only real if the balance it snapshots is.
        ->and($publisher->balance)->toBe('0.091000')
        ->and($publisher->lifetime_earnings)->toBe('0.091000');

    $audit = AuditLog::query()->where('action', 'conversion.approved')->sole();

    expect($audit->user_id)->toBe($this->admin->id)
        ->and($audit->subject_id)->toBe($conversion->id)
        ->and($audit->before['status'])->toBe('pending')
        ->and($audit->after['status'])->toBe('approved');
});

it('rejects with a reason and moves nothing', function () {
    Queue::fake();

    $conversion = ($this->flag)();

    Livewire::test(ReviewQueue::class)
        ->callTableAction('reject', $conversion, data: ['reason' => 'Datacenter IP, no session depth'])
        ->assertHasNoTableActionErrors();

    $publisher = Publisher::query()->findOrFail($conversion->publisher_id);

    expect($conversion->fresh()->status)->toBe(ConversionStatus::Rejected)
        ->and($conversion->fresh()->rejected_reason)->toBe('Datacenter IP, no session depth')
        // No entry was ever written, so there is none to reverse.
        ->and(LedgerEntry::query()->count())->toBe(0)
        ->and($publisher->balance)->toBe('0.000000');

    $audit = AuditLog::query()->where('action', 'conversion.rejected')->sole();

    expect($audit->user_id)->toBe($this->admin->id)
        ->and($audit->after['reason'])->toBe('Datacenter IP, no session depth');
});

it('pays an approved conversion once, however many times approve is pressed', function () {
    Queue::fake();

    $conversion = ($this->flag)();

    Livewire::test(ReviewQueue::class)->callTableAction('approve', $conversion);
    // Not defensive: two admins working the same queue is the normal way this
    // happens, and the second press must not be a second payment.
    app(ConversionProcessor::class)->approvePending($conversion->fresh(), $this->admin);

    expect(LedgerEntry::query()->count())->toBe(1)
        ->and(Publisher::query()->findOrFail($conversion->publisher_id)->balance)->toBe('0.091000');
});
