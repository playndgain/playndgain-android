<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Database\QueryException;

/**
 * The public name, mailboxes and domain.
 *
 * `.env` is the default (APP_NAME, APP_URL, MAIL_*, PLAYNDGAIN_SUPPORT_EMAIL,
 * INVOICE_*). A non-empty `settings` row wins, so the admin panel can override
 * without a deploy. Blank setting means "use env".
 */
final class Identity
{
    public static function name(): string
    {
        return self::settingOr('platform_name', (string) config('app.name'));
    }

    public static function supportEmail(): string
    {
        return self::settingOr('support_email', (string) config('playndgain.support_email'));
    }

    public static function url(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /**
     * @return array{name: string, address: string, city: string, country: string, vat: string, website: string, email: string, directors: string, vat_rate: string}
     */
    public static function invoice(): array
    {
        $invoice = config('playndgain.invoice');

        return [
            'name' => self::settingOr('invoice_company_name', (string) ($invoice['company_name'] ?: self::name())),
            'address' => self::settingOr('invoice_address', (string) ($invoice['address'] ?? '')),
            'city' => self::settingOr('invoice_city', (string) ($invoice['city'] ?? '')),
            'country' => self::settingOr('invoice_country', (string) ($invoice['country'] ?? '')),
            'vat' => self::settingOr('invoice_vat', (string) ($invoice['vat'] ?? '')),
            'website' => self::settingOr('invoice_website', (string) ($invoice['website'] ?: self::url())),
            'email' => self::settingOr('invoice_email', (string) ($invoice['email'] ?: self::supportEmail())),
            'directors' => self::settingOr('invoice_directors', (string) ($invoice['directors'] ?? '')),
            'vat_rate' => self::settingOr('invoice_vat_rate', (string) ($invoice['vat_rate'] ?? '0')),
        ];
    }

    /**
     * @return array{name: string, address: string, city: string, country: string, vat: string, website: string, email: string, directors: string}
     */
    public static function invoiceIssuer(): array
    {
        $issuer = self::invoice();
        unset($issuer['vat_rate']);

        return $issuer;
    }

    private static function settingOr(string $key, string $fallback): string
    {
        try {
            $value = Setting::get($key);
        } catch (QueryException) {
            return $fallback;
        }

        return is_string($value) && $value !== '' ? $value : $fallback;
    }
}
