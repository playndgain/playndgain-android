<?php

namespace App\Services\Invoices;

/**
 * One payout document. Built from a withdrawal; the PDF is a view of this.
 *
 * @phpstan-type Line array{description: string, amount: string}
 * @phpstan-type Issuer array{name: string, address: string, city: string, country: string, vat: string, website: string, email: string, directors: string}
 */
final readonly class CreditNote
{
    /**
     * @param  list<Line>  $lines
     * @param  Issuer  $issuer
     */
    public function __construct(
        public string $number,
        public string $publisherId,
        public string $issueDate,
        public string $dueDate,
        public string $periodStart,
        public string $periodEnd,
        public string $billingEmail,
        public string $payeeName,
        public string $payeeAddress,
        public string $payeeCityLine,
        public string $payeeCountry,
        public string $payeeVat,
        public array $lines,
        public string $subtotal,
        public string $vatRate,
        public string $vatAmount,
        public string $total,
        public string $paymentInstruction,
        public array $issuer,
    ) {}

    public function filename(): string
    {
        return "Credit-Note_{$this->issueDate}_{$this->number}.pdf";
    }
}
