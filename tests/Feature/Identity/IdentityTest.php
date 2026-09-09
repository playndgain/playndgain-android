<?php

use App\Models\Setting;
use App\Support\Identity;
use Illuminate\Notifications\Messages\MailMessage;

it('follows env when identity settings are blank', function () {
    config()->set('app.name', 'CoinWall');
    config()->set('app.url', 'https://coinwall.test');
    config()->set('playndgain.support_email', 'hello@coinwall.test');
    config()->set('playndgain.invoice', [
        'company_name' => 'CoinWall Ltd',
        'address' => '1 Ledger Street',
        'city' => 'Cairo',
        'country' => 'Egypt',
        'vat' => 'EG-1',
        'website' => 'https://coinwall.test',
        'email' => 'billing@coinwall.test',
        'directors' => 'A. Founder',
        'vat_rate' => '14',
    ]);

    expect(Identity::name())->toBe('CoinWall')
        ->and(Identity::supportEmail())->toBe('hello@coinwall.test')
        ->and(Identity::url())->toBe('https://coinwall.test')
        ->and(Identity::invoiceIssuer())->toMatchArray([
            'name' => 'CoinWall Ltd',
            'address' => '1 Ledger Street',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'vat' => 'EG-1',
            'website' => 'https://coinwall.test',
            'email' => 'billing@coinwall.test',
            'directors' => 'A. Founder',
        ]);
});

it('lets a non-empty setting win over env', function () {
    config()->set('app.name', 'CoinWall');
    config()->set('playndgain.support_email', 'hello@coinwall.test');

    Setting::set('platform_name', 'Vault Co');
    Setting::set('support_email', 'ops@vault.test');
    Setting::set('invoice_company_name', 'Vault Co Ltd');

    expect(Identity::name())->toBe('Vault Co')
        ->and(Identity::supportEmail())->toBe('ops@vault.test')
        ->and(Identity::invoice()['name'])->toBe('Vault Co Ltd');
});

it('prints invoices under the platform name unless a legal name is set', function () {
    config()->set('app.name', 'CoinWall');
    config()->set('playndgain.invoice.company_name', null);
    config()->set('playndgain.invoice.website', null);
    config()->set('playndgain.invoice.email', null);
    config()->set('app.url', 'https://coinwall.test');
    config()->set('playndgain.support_email', 'hello@coinwall.test');

    expect(Identity::invoiceIssuer())->toMatchArray([
        'name' => 'CoinWall',
        'website' => 'https://coinwall.test',
        'email' => 'hello@coinwall.test',
    ]);
});

it('treats a blank setting as use env', function () {
    config()->set('app.name', 'CoinWall');

    Setting::set('platform_name', '');

    expect(Identity::name())->toBe('CoinWall');
});

it('paints mail chrome with the configured name', function () {
    config()->set('app.name', 'CoinWall');

    $html = (string) (new MailMessage)
        ->subject('Verify your email')
        ->greeting('Hello')
        ->line('Confirm this address.')
        ->render();

    expect($html)
        ->toContain('CoinWall')
        ->not->toContain('>PLAYNDGAIN<');
});
