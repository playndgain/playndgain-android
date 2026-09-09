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
it('paints markdown mail with the brand palette, not Laravel grey', function () {
    $html = (string) (new MailMessage)
        ->subject('Verify your email')
        ->greeting('Hello')
        ->line('Confirm this address to finish creating your account.')
        ->action('Verify email', 'https://playndgain.test/verify')
        ->render();

    expect($html)
        ->toContain('#0A0B14') // ink-950: the page ground
        ->toContain('#11131F') // ink-900: the card's header band
        ->toContain('#FFB020') // amber-400: the mark and the button
        ->toContain('#FFFFFF') // paper-0: the body
        ->toContain(Identity::name())
        ->not->toContain('laravel.com/img/notification-logo')
        ->not->toContain('#fafafa')
        ->not->toContain('#DFC08A'); // the Advault gold is gone
});

it('brands admin alert mail the same way', function () {
    $html = (string) (new AdminAlertNotification('ledger.drift', true))
        ->toMail(new AnonymousNotifiable)
        ->render();

    expect($html)
        ->toContain('#FFB020')
        ->toContain('#0A0B14')
        ->toContain(Identity::name())
        ->toContain('ledger.drift')
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
        ->toContain('#FFB020')
        ->toContain('#0A0B14')
        ->toContain('Dana Okonkwo')
        ->toContain('dana@example.com')
        ->toContain('We run a network with 400 live offers.');
});
