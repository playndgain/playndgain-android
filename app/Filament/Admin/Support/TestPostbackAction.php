<?php

namespace App\Filament\Admin\Support;

use App\Enums\OutgoingPostbackKind;
use App\Models\App;
use App\Services\Money\TestPostbackService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * One button, both panels — docs/07 §5, docs/08 §4.
 *
 * The admin fires it at a publisher's endpoint to settle a support ticket; the
 * publisher fires it at their own to prove the integration works. Same request,
 * same result shown, so the two sides of that conversation see the same thing.
 */
final class TestPostbackAction
{
    public static function make(string $name = 'test_postback'): Action
    {
        return Action::make($name)
            ->label('Send test postback')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('info')
            ->disabled(fn (App $record): bool => blank($record->postback_url))
            ->tooltip(fn (App $record): ?string => blank($record->postback_url)
                ? 'No callback URL configured on this app'
                : null)
            ->modalHeading('Send test postback')
            ->modalDescription('Fires a real request at the configured callback URL. Nothing is credited on our side. Your endpoint will credit or reverse whoever you name below.')
            ->modalSubmitActionLabel('Send')
            ->schema([
                TextInput::make('user_id')
                    ->label('User ID')
                    ->required()
                    ->maxLength(191)
                    ->placeholder('The same value you pass as userid on the wall')
                    ->helperText('Must exist on your site. A made-up id is a failed lookup, not a failed signature.'),
                Select::make('status')
                    ->label('Status')
                    ->required()
                    ->default('credited')
                    ->options([
                        'credited' => 'Credited',
                        'reversed' => 'Reversed (chargeback)',
                    ])
                    ->helperText('A reversal sends a negative payout and a new signature. It uses a fresh trans_id — it does not undo a previous test.'),
            ])
            ->action(function (App $record, array $data): void {
                $kind = ($data['status'] ?? 'credited') === 'reversed'
                    ? OutgoingPostbackKind::Reversal
                    : OutgoingPostbackKind::Credit;

                $result = app(TestPostbackService::class)->send(
                    $record,
                    $kind,
                    isset($data['user_id']) ? trim((string) $data['user_id']) : null,
                );

                Notification::make()
                    ->title($result['ok'] ? 'Delivered' : 'Failed')
                    ->body(sprintf(
                        "HTTP %s in %dms\n%s",
                        $result['status'] ?? 'no response',
                        $result['duration_ms'],
                        $result['body'] === '' ? '(empty body)' : $result['body'],
                    ))
                    ->status($result['ok'] ? 'success' : 'danger')
                    ->persistent()
                    ->send();
            });
    }
}
