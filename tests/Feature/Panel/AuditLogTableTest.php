<?php

use App\Enums\AuditActionFamily;
use App\Filament\Admin\Resources\AuditLogs\AuditLogResource;
use App\Filament\Admin\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Models\AuditLog;
use App\Models\Publisher;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;

beforeEach(function () {
    Config::set('advault.require_admin_2fa', false);
    Filament::setCurrentPanel('admin');

    $this->admin = User::factory()->admin()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['aaaa-bbbb'],
    ]);

    $this->actingAs($this->admin);
});

it('classifies known admin actions into families', function (string $action, AuditActionFamily $family) {
    expect(AuditActionFamily::fromAction($action))->toBe($family);
})->with([
    ['publisher.approved', AuditActionFamily::Status],
    ['publisher.suspended', AuditActionFamily::Status],
    ['publisher.banned', AuditActionFamily::Status],
    ['publisher.updated', AuditActionFamily::Catalog],
    ['publisher.balance_adjusted', AuditActionFamily::Money],
    ['publisher.impersonated', AuditActionFamily::Access],
    ['publisher.impersonation_ended', AuditActionFamily::Access],
    ['conversion.approved', AuditActionFamily::Money],
    ['conversion.rejected', AuditActionFamily::Money],
    ['conversion.force_reversed', AuditActionFamily::Money],
    ['conversion.postback_resent', AuditActionFamily::Money],
    ['withdrawal.requested', AuditActionFamily::Money],
    ['withdrawal.approved', AuditActionFamily::Money],
    ['withdrawal.paid', AuditActionFamily::Money],
    ['withdrawal.rejected', AuditActionFamily::Money],
    ['app.secret_regenerated', AuditActionFamily::Access],
    ['admin.2fa_reset', AuditActionFamily::Access],
    ['payment_method.revealed', AuditActionFamily::Access],
    ['network.credentials_revealed', AuditActionFamily::Access],
    ['network.status', AuditActionFamily::Status],
    ['offer.created', AuditActionFamily::Catalog],
    ['offer.blocked', AuditActionFamily::Status],
    ['offer.unblocked', AuditActionFamily::Status],
    ['settings.updated', AuditActionFamily::Settings],
    ['fraud_rule.toggled', AuditActionFamily::Settings],
    ['ip_provider.updated', AuditActionFamily::Settings],
    ['incoming_postback.matched', AuditActionFamily::Catalog],
    ['end_user.unbanned', AuditActionFamily::Status],
    ['end_user.ban_made_permanent', AuditActionFamily::Status],
]);

it('renders a human event, the actor, and the before/after change', function () {
    $publisher = Publisher::factory()->create();

    AuditLog::query()->create([
        'user_id' => $this->admin->id,
        'action' => 'publisher.approved',
        'subject_type' => $publisher->getMorphClass(),
        'subject_id' => $publisher->id,
        'before' => ['status' => 'pending'],
        'after' => ['status' => 'approved'],
        'ip' => '203.0.113.10',
        'created_at' => now(),
    ]);

    Livewire::test(ListAuditLogs::class)
        ->assertSuccessful()
        ->assertSee('Publisher approved')
        ->assertSee($this->admin->name)
        ->assertSee($this->admin->email)
        ->assertSee('Publisher #'.$publisher->id)
        ->assertSee('pending')
        ->assertSee('approved')
        ->assertSee('Status');
});

it('keeps money events on the money tab and drops status events', function () {
    $publisher = Publisher::factory()->create();

    $status = AuditLog::query()->create([
        'user_id' => $this->admin->id,
        'action' => 'publisher.approved',
        'subject_type' => $publisher->getMorphClass(),
        'subject_id' => $publisher->id,
        'after' => ['status' => 'approved'],
        'created_at' => now()->subMinute(),
    ]);

    $money = AuditLog::query()->create([
        'user_id' => $this->admin->id,
        'action' => 'publisher.balance_adjusted',
        'subject_type' => $publisher->getMorphClass(),
        'subject_id' => $publisher->id,
        'after' => ['amount' => '12.50', 'reason' => 'goodwill'],
        'created_at' => now(),
    ]);

    Livewire::test(ListAuditLogs::class)
        ->assertCanSeeTableRecords([$status, $money])
        ->set('activeTab', 'money')
        ->assertCanSeeTableRecords([$money])
        ->assertCanNotSeeTableRecords([$status])
        ->set('activeTab', 'status')
        ->assertCanSeeTableRecords([$status])
        ->assertCanNotSeeTableRecords([$money]);
});

it('opens the inspect slide-over with the raw action and the subject', function () {
    $publisher = Publisher::factory()->create();

    $log = AuditLog::query()->create([
        'user_id' => $this->admin->id,
        'action' => 'publisher.impersonated',
        'subject_type' => $publisher->getMorphClass(),
        'subject_id' => $publisher->id,
        'ip' => '198.51.100.20',
        'created_at' => now(),
    ]);

    Livewire::test(ListAuditLogs::class)
        ->assertTableActionVisible('inspect', $log)
        ->mountTableAction('inspect', $log)
        ->assertActionMounted();

    $html = view('filament.admin.audit-inspect', [
        'record' => $log->load('user'),
        'subjectUrl' => AuditLogResource::subjectUrl($log),
    ])->render();

    expect($html)
        ->toContain('What changed')
        ->toContain('publisher.impersonated')
        ->toContain('198.51.100.20')
        ->toContain('Publisher #'.$publisher->id);
});
