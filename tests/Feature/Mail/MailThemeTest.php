<?php

use App\Mail\ContactMessage;
use App\Notifications\AdminAlertNotification;
use App\Support\Identity;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The published mail theme is the chrome for every MailMessage — verification,
 * password reset, admin alerts, backups. A second theme is a second palette.
 */
it('paints markdown mail with the vault palette, not Laravel grey', function () {
    $html = (string) (new MailMessage)
        ->subject('Verify your email')
        ->greeting('Hello')
        ->line('Confirm this address to finish creating your account.')
        ->action('Verify email', 'https://advault.test/verify')
        ->render();

    expect($html)
        ->toContain('#08090A')
        ->toContain('#14171B')
        ->toContain('#F5A524')
        ->toContain(Identity::name())
        ->not->toContain('laravel.com/img/notification-logo')
        ->not->toContain('#fafafa');
});

it('brands admin alert mail the same way', function () {
    $html = (string) (new AdminAlertNotification('ledger.drift', true))
        ->toMail(new AnonymousNotifiable)
        ->render();

    expect($html)
        ->toContain('#F5A524')
        ->toContain('#08090A')
        ->toContain(Identity::name())
        ->toContain('cached publisher balance disagrees');
});

it('renders the contact form as branded html with a text part', function () {
    $mail = new ContactMessage(
        'Dana Okonkwo',
        'dana@example.com',
        'Supply',
        'We run a network with 400 live offers.',
    );

    expect($mail->content()->text)->toBe('mail.contact')
        ->and($mail->content()->markdown)->toBe('mail.contact-html');

    $html = $mail->render();

    expect($html)
        ->toContain('#F5A524')
        ->toContain('#08090A')
        ->toContain('Dana Okonkwo')
        ->toContain('dana@example.com')
        ->toContain('We run a network with 400 live offers.');
});
