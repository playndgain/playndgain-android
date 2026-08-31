<?php

namespace App\Services\Invoices;

use App\Enums\ConversionStatus;
use App\Enums\PaymentMethodType;
use App\Enums\WithdrawalStatus;
use App\Models\Conversion;
use App\Models\Withdrawal;
use App\Services\Money\Money;
use App\Support\Identity;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Locale;

/**
 * Turns a paid (or processing) withdrawal into the credit-note the publisher
 * would get from a network: number, period, per-app monthly lines, VAT, and
 * the destination the money is going to.
 */
final class CreditNoteBuilder
{
    public function fromWithdrawal(Withdrawal $withdrawal): CreditNote
    {
        if (! in_array($withdrawal->status, [WithdrawalStatus::Processing, WithdrawalStatus::Paid], true)) {
            throw new InvalidArgumentException('A credit note is only issued once a withdrawal is being paid.');
        }

        $withdrawal->loadMissing(['publisher.user']);

        $publisher = $withdrawal->publisher;
        $issued = ($withdrawal->paid_at ?? $withdrawal->processed_at ?? $withdrawal->requested_at ?? now())->toImmutable();
        $periodEnd = ($withdrawal->requested_at ?? $issued)->toImmutable();
        $periodStart = $this->periodStart($withdrawal, $periodEnd);
        $lines = $this->lines($withdrawal, $periodStart, $periodEnd);
        $subtotal = $this->sumLines($lines);
        $vatRate = Money::format(Identity::invoice()['vat_rate'], 2);
        $vatAmount = Money::share($subtotal, $vatRate);
        $total = bcadd($subtotal, $vatAmount, Money::SCALE);

        return new CreditNote(
            number: 'CN-'.str_pad((string) $withdrawal->id, 5, '0', STR_PAD_LEFT),
            publisherId: (string) $publisher->pub_id,
            issueDate: $issued->toDateString(),
            dueDate: $issued->addDays(30)->toDateString(),
            periodStart: $periodStart->toDateString(),
            periodEnd: $periodEnd->toDateString(),
            billingEmail: (string) $publisher->user?->email,
            payeeName: (string) ($publisher->company_name ?: $publisher->user?->name ?: 'Publisher'),
            payeeAddress: (string) ($publisher->billing_address ?? ''),
            payeeCityLine: trim(implode(' ', array_filter([
                $publisher->billing_postal_code,
                $publisher->billing_city,
            ]))),
            payeeCountry: $this->countryName((string) $publisher->country),
            payeeVat: (string) ($publisher->vat_number ?? ''),
            lines: $lines,
            subtotal: Money::format($subtotal, 2),
            vatRate: $vatRate,
            vatAmount: Money::format($vatAmount, 2),
            total: Money::format($total, 2),
            paymentInstruction: $this->paymentInstruction($withdrawal),
            issuer: $this->issuer(),
        );
    }

    /**
     * @return list<array{description: string, amount: string}>
     */
    private function lines(Withdrawal $withdrawal, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $rows = Conversion::query()
            ->withoutGlobalScopes()
            ->with('app:id,app_id,name')
            ->where('publisher_id', $withdrawal->publisher_id)
            ->where('is_test', false)
            ->whereIn('status', [ConversionStatus::Approved, ConversionStatus::Reversed])
            ->where('created_at', '>', $from)
            ->where('created_at', '<=', $to)
            ->orderBy('created_at')
            ->get();

        $buckets = [];

        foreach ($rows as $conversion) {
            $key = $conversion->created_at->format('Y-m').'|'.($conversion->app_id ?? 0);
            $sign = $conversion->status === ConversionStatus::Reversed ? '-' : '';
            $amount = $sign.Money::format($conversion->payout);

            if (! isset($buckets[$key])) {
                $app = $conversion->app;
                $label = $app !== null
                    ? '#'.$app->app_id.' '.$app->name
                    : ($conversion->offer_name ?: 'App');

                $buckets[$key] = [
                    'month' => $conversion->created_at->format('m-Y'),
                    'label' => $label,
                    'amount' => '0.000000',
                ];
            }

            $buckets[$key]['amount'] = bcadd($buckets[$key]['amount'], $amount, Money::SCALE);
        }

        $lines = [];

        foreach ($buckets as $bucket) {
            if (bccomp($bucket['amount'], '0', Money::SCALE) === 0) {
                continue;
            }

            $lines[] = [
                'description' => "Publisher Earnings {$bucket['month']} ({$bucket['label']})",
                'amount' => Money::format($bucket['amount'], 2),
            ];
        }

        if ($lines !== []) {
            return $lines;
        }

        return [[
            'description' => 'Publisher payout',
            'amount' => Money::format($withdrawal->amount, 2),
        ]];
    }

    /**
     * @param  list<array{description: string, amount: string}>  $lines
     */
    private function sumLines(array $lines): string
    {
        $sum = '0.000000';

        foreach ($lines as $line) {
            $sum = bcadd($sum, Money::format($line['amount']), Money::SCALE);
        }

        return $sum;
    }

    private function periodStart(Withdrawal $withdrawal, DateTimeInterface $periodEnd): CarbonImmutable
    {
        $previous = Withdrawal::query()
            ->withoutGlobalScopes()
            ->where('publisher_id', $withdrawal->publisher_id)
            ->where('status', WithdrawalStatus::Paid)
            ->whereKeyNot($withdrawal->id)
            ->where('requested_at', '<', $periodEnd)
            ->orderByDesc('requested_at')
            ->value('requested_at');

        if ($previous !== null) {
            return CarbonImmutable::parse($previous);
        }

        return CarbonImmutable::parse($periodEnd)->startOfMonth();
    }

    private function paymentInstruction(Withdrawal $withdrawal): string
    {
        $details = json_decode((string) $withdrawal->payment_details, true);
        $details = is_array($details) ? $details : [];

        return match ($withdrawal->method) {
            PaymentMethodType::Paypal => 'PayPal: '.($details['email'] ?? ''),
            PaymentMethodType::Wise => 'Wise: '.($details['email'] ?? ''),
            PaymentMethodType::Crypto => trim(($details['asset'] ?? '').' '.($details['network'] ?? '').': '.($details['address'] ?? '')),
            PaymentMethodType::Bank => 'Bank: '.($details['iban'] ?? $details['account'] ?? ''),
        };
    }

    /** @return array{name: string, address: string, city: string, country: string, vat: string, website: string, email: string, directors: string} */
    private function issuer(): array
    {
        return Identity::invoiceIssuer();
    }

    private function countryName(string $code): string
    {
        if ($code === '') {
            return '';
        }

        $name = Locale::getDisplayRegion('-'.$code, 'en');

        return is_string($name) && $name !== '' && $name !== $code ? $name : $code;
    }
}
