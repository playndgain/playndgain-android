<?php

namespace App\Filament\Publisher\Pages;

use App\Filament\Publisher\Support\CurrentPublisher;
use App\Models\PayoutStatement;
use App\Services\Money\Money;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Chargeback invoice for one statement month — docs/08 §10.
 *
 * Payment history lists the month. This page is the detail: offers that
 * reversed, compacted to one row each. It is not in the nav.
 */
class StatementInvoice extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $slug = 'payments/{record}/invoice';

    protected static ?string $title = 'Chargeback invoice';

    protected string $view = 'filament.publisher.pages.statement-invoice';

    public PayoutStatement $statement;

    public function mount(int|string $record): void
    {
        $this->statement = PayoutStatement::query()
            ->where('publisher_id', CurrentPublisher::id())
            ->findOrFail($record);
    }

    public function getTitle(): string|Htmlable
    {
        return $this->statement->periodLabel().' chargebacks';
    }

    public function getHeading(): string|Htmlable
    {
        return $this->getTitle();
    }

    public function getSubheading(): ?string
    {
        return 'Same offer is one line.';
    }

    /** @return list<array{app: string, offer: string, description: string, count: int, amount: string}> */
    public function offers(): array
    {
        return $this->statement->chargebacksByOffer();
    }

    public function reversalCount(): int
    {
        return array_sum(array_column($this->offers(), 'count'));
    }

    public function dollars(string $amount): string
    {
        return Money::dollars($amount);
    }

    public function paymentsUrl(): string
    {
        return Payments::getUrl();
    }
}
