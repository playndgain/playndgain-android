<?php

namespace App\Filament\Publisher\Resources\Withdrawals\Pages;

use App\Filament\Publisher\Resources\Withdrawals\WithdrawalResource;
use App\Filament\Publisher\Support\CurrentPublisher;
use App\Filament\Publisher\Widgets\WithdrawalSummary;
use App\Models\PaymentMethod;
use App\Services\Money\WithdrawalService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use RuntimeException;

class ListWithdrawals extends ListRecords
{
    protected static string $resource = WithdrawalResource::class;

    protected function getHeaderWidgets(): array
    {
        return [WithdrawalSummary::class];
    }

    /**
     * One button. When it is unavailable it says which of the five reasons
     * applies, because a greyed-out button with no explanation is a support
     * ticket — docs/08 §10.
     */
    protected function getHeaderActions(): array
    {
        $publisher = CurrentPublisher::get();
        $service = app(WithdrawalService::class);
        $blocked = $publisher === null ? 'No publisher account on this login.' : $service->blockedReason($publisher);

        return [
            Action::make('request')
                ->label('Request withdrawal')
                ->icon(Heroicon::OutlinedBanknotes)
                ->disabled($blocked !== null)
                ->tooltip($blocked)
                ->schema([
                    TextInput::make('amount')
                        ->numeric()
                        ->required()
                        ->prefix('$')
                        ->default(fn (): string => $publisher?->withdrawableBalance() ?? '0')
                        ->minValue(fn (): string => $publisher === null ? '20' : $service->minimumPayout($publisher))
                        ->maxValue(fn (): string => $publisher?->withdrawableBalance() ?? '0')
                        ->live(onBlur: true),
                    Select::make('payment_method_id')
                        ->label('Pay to')
                        ->required()
                        ->options(fn (): array => PaymentMethod::query()
                            ->where('publisher_id', CurrentPublisher::id())
                            ->get()
                            ->mapWithKeys(fn (PaymentMethod $method): array => [
                                $method->id => trim(($method->label ?? '').' · '.$method->type->value, ' ·'),
                            ])
                            ->all())
                        ->live(),
                    // The confirmation step: the exact destination, spelled out,
                    // before anything is written.
                    Text::make(fn (Get $get): HtmlString => new HtmlString(self::confirmation($get))),
                ])
                ->action(function (array $data) use ($publisher, $service): void {
                    if ($publisher === null) {
                        return;
                    }

                    $method = PaymentMethod::query()
                        ->where('publisher_id', $publisher->id)
                        ->findOrFail($data['payment_method_id']);

                    try {
                        $service->request($publisher, (string) $data['amount'], $method);
                    } catch (RuntimeException $e) {
                        Notification::make()->title('Not allowed')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()
                        ->title('Requested')
                        ->body('We review payouts by hand. You will be notified when it moves.')
                        ->success()
                        ->send();
                }),
        ];
    }

    private static function confirmation(Get $get): string
    {
        $methodId = $get('payment_method_id');

        if (blank($methodId)) {
            return '<span class="text-gray-400">Pick a payment method to see exactly where this goes.</span>';
        }

        $method = PaymentMethod::query()
            ->where('publisher_id', CurrentPublisher::id())
            ->find($methodId);

        if ($method === null) {
            return '<span class="text-danger-400">That payment method no longer exists.</span>';
        }

        $details = $method->details;
        $destination = (string) ($details['email'] ?? $details['address'] ?? $details['account'] ?? $details['iban'] ?? 'unknown');

        return sprintf(
            '<span class="text-gray-300">Paying <strong>$%s</strong> to <strong>%s</strong> (%s). '
            .'The destination is snapshotted now — editing the method later will not move this payout.</span>',
            number_format((float) $get('amount'), 2),
            e($destination),
            e($method->type->value),
        );
    }
}
