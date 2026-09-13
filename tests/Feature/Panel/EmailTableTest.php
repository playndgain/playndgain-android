<?php

use App\Filament\Admin\Resources\Emails\Pages\ListEmails;
use App\Filament\Admin\Resources\Emails\Pages\ViewEmail;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use RickDBCN\FilamentEmail\Models\Email;

function actingAsEmailAdmin(): User
{
    Config::set('playndgain.require_admin_2fa', false);

    $admin = User::factory()->admin()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['aaaa-bbbb'],
    ]);

    test()->actingAs($admin);

    return $admin;
}

it('serves the admin sent mail page with clean filters', function () {
    actingAsEmailAdmin();

    Email::query()->create([
        'from' => 'no-reply@playndgain.com',
        'to' => 'partner@example.com',
        'subject' => 'Monthly Statement for August',
        'text_body' => 'Your statement is ready.',
        'attachments' => null,
        'created_at' => now(),
    ]);

    test()->get('/admin/emails')
        ->assertSuccessful()
        ->assertSee('Sent mail')
        ->assertSee('partner@example.com')
        ->assertSee('Monthly Statement for August');
});

it('filters sent mail by recipient', function () {
    actingAsEmailAdmin();

    $emailAlice = Email::query()->create([
        'from' => 'billing@playndgain.com',
        'to' => 'alice@company.test',
        'subject' => 'Invoice #1042',
        'text_body' => 'See invoice attached.',
        'created_at' => now(),
    ]);

    $emailBob = Email::query()->create([
        'from' => 'support@playndgain.com',
        'to' => 'bob@company.test',
        'subject' => 'Welcome to PLAYNDGAIN',
        'text_body' => 'Your account is active.',
        'created_at' => now(),
    ]);

    Livewire::test(ListEmails::class)
        ->filterTable('to', ['email' => 'alice'])
        ->assertCanSeeTableRecords([$emailAlice])
        ->assertCanNotSeeTableRecords([$emailBob]);
});

it('filters sent mail by subject', function () {
    actingAsEmailAdmin();

    $emailInvoice = Email::query()->create([
        'from' => 'billing@playndgain.com',
        'to' => 'alice@company.test',
        'subject' => 'Invoice #1042',
        'text_body' => 'See invoice attached.',
        'created_at' => now(),
    ]);

    $emailWelcome = Email::query()->create([
        'from' => 'support@playndgain.com',
        'to' => 'bob@company.test',
        'subject' => 'Welcome to PLAYNDGAIN',
        'text_body' => 'Your account is active.',
        'created_at' => now(),
    ]);

    Livewire::test(ListEmails::class)
        ->filterTable('subject', ['search' => 'Welcome'])
        ->assertCanSeeTableRecords([$emailWelcome])
        ->assertCanNotSeeTableRecords([$emailInvoice]);
});

it('filters sent mail by attachments', function () {
    actingAsEmailAdmin();

    $emailWithAttachment = Email::query()->create([
        'from' => 'billing@playndgain.com',
        'to' => 'alice@company.test',
        'subject' => 'Invoice #1042',
        'text_body' => 'See invoice attached.',
        'attachments' => [['name' => 'inv.pdf', 'path' => 'inv.pdf']],
        'created_at' => now(),
    ]);

    $emailPlain = Email::query()->create([
        'from' => 'support@playndgain.com',
        'to' => 'bob@company.test',
        'subject' => 'Welcome to PLAYNDGAIN',
        'text_body' => 'Your account is active.',
        'attachments' => null,
        'created_at' => now(),
    ]);

    Livewire::test(ListEmails::class)
        ->filterTable('attachments', true)
        ->assertCanSeeTableRecords([$emailWithAttachment])
        ->assertCanNotSeeTableRecords([$emailPlain]);

    Livewire::test(ListEmails::class)
        ->filterTable('attachments', false)
        ->assertCanSeeTableRecords([$emailPlain])
        ->assertCanNotSeeTableRecords([$emailWithAttachment]);
});

it('filters sent mail by date preset', function () {
    actingAsEmailAdmin();

    $todayEmail = Email::query()->create([
        'from' => 'billing@playndgain.com',
        'to' => 'alice@company.test',
        'subject' => 'Invoice #1042',
        'text_body' => 'See invoice attached.',
        'created_at' => now(),
    ]);

    $oldEmail = Email::query()->create([
        'from' => 'support@playndgain.com',
        'to' => 'bob@company.test',
        'subject' => 'Old notice',
        'text_body' => 'Old notice body.',
        'created_at' => now()->subDays(10),
    ]);

    Livewire::test(ListEmails::class)
        ->filterTable('sent', ['preset' => 'today'])
        ->assertCanSeeTableRecords([$todayEmail])
        ->assertCanNotSeeTableRecords([$oldEmail]);

    Livewire::test(ListEmails::class)
        ->filterTable('sent', ['preset' => '30d'])
        ->assertCanSeeTableRecords([$todayEmail, $oldEmail]);
});

it('isolates sent-mail html from livewire so a preview cannot break the page', function () {
    actingAsEmailAdmin();

    $email = Email::query()->create([
        'from' => 'billing@playndgain.com',
        'to' => 'alice@company.test',
        'subject' => 'Invoice #1042',
        'html_body' => '<!DOCTYPE html><html><body><p>Pay this invoice</p><style>@media (max-width: 600px) { p { color: red; } }</style><script>alert(1)</script></body></html>',
        'text_body' => 'Pay this invoice',
        'created_at' => now(),
    ]);

    Livewire::test(ViewEmail::class, ['record' => $email->getKey()])
        ->assertSuccessful()
        ->assertSee('Pay this invoice', false)
        ->assertSee('wire:ignore', false)
        ->assertSee('sandbox=', false)
        ->assertSee('srcdoc=', false);
});
