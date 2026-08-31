<?php

namespace App\Filament\Admin\Resources\Conversions\Pages;

use App\Filament\Admin\Resources\Conversions\ConversionResource;
use App\Models\Conversion;
use App\Models\FraudEvent;
use App\Models\IncomingPostback;
use App\Models\OfferClick;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The whole story of one conversion — docs/07 §6.
 *
 * The click, the raw postback the network sent, and every delivery attempt we
 * made, on one page. A support thread about a missing conversion is settled
 * here or not at all.
 */
class ViewConversion extends ViewRecord
{
    protected static string $resource = ConversionResource::class;

    /**
     * Memoised because RepeatableEntry re-reads its state to decide whether the
     * children it built are stale, and compares by identity — a fresh
     * `get()` every time never matches itself and re-queries on each ask.
     *
     * @var Collection<int, FraudEvent>|null
     */
    private ?Collection $fraudEventCache = null;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Conversion')->columns(4)->schema([
                TextEntry::make('trans_id')->label('Transaction ID')->copyable(),
                TextEntry::make('network_txid')->label('Network TX')->copyable(),
                TextEntry::make('status')->badge(),
                IconEntry::make('is_test')->label('Test')->boolean(),
                TextEntry::make('publisher.pub_id')->label('Publisher')->prefix('#'),
                TextEntry::make('app.name')->label('App'),
                TextEntry::make('network.name')->label('Network'),
                TextEntry::make('offer_name')->label('Offer'),
                TextEntry::make('revenue')->money('USD'),
                TextEntry::make('payout')->money('USD'),
                TextEntry::make('rev_share_applied')->label('Rev share')->suffix('%'),
                TextEntry::make('currency_amount')->label('User reward'),
                TextEntry::make('end_user_id')->label('End user'),
                TextEntry::make('country')->placeholder('—'),
                TextEntry::make('created_at')->dateTime(),
                TextEntry::make('clears_at')->label('Clears')->dateTime()->placeholder('—'),
                TextEntry::make('rejected_reason')->label('Rejected reason')->placeholder('—')->columnSpan(2),
                TextEntry::make('reversed_at')->label('Reversed')->dateTime()->placeholder('—'),
            ]),

            Section::make('Click')
                ->columns(4)
                ->visible(fn (Conversion $record): bool => $record->offerClick !== null)
                ->schema([
                    TextEntry::make('offerClick.click_id')->label('Click ID')->copyable(),
                    TextEntry::make('offerClick.ip')->label('IP'),
                    TextEntry::make('offerClick.country')->label('Country')->placeholder('—'),
                    TextEntry::make('offerClick.device_type')->label('Device')->placeholder('—'),
                    TextEntry::make('offerClick.os')->label('OS')->placeholder('—'),
                    TextEntry::make('offerClick.browser')->label('Browser')->placeholder('—'),
                    TextEntry::make('offerClick.risk_score')->label('Risk score'),
                    TextEntry::make('offerClick.created_at')->label('Clicked')->dateTime(),
                    TextEntry::make('offerClick.user_agent')->label('User agent')->columnSpanFull(),
                ]),

            Section::make('Incoming postback')
                ->schema([
                    KeyValueEntry::make('raw')
                        ->label('')
                        ->state(fn (Conversion $record): array => self::rawPostback($record)),
                ]),

            Section::make('Fraud events')
                ->description('Every rule that fired, blocking or not. This is the answer to "why is this pending".')
                ->schema([
                    RepeatableEntry::make('fraud_events')
                        ->label('')
                        ->columns(5)
                        ->state(fn (Conversion $record): Collection => $this->fraudEvents($record))
                        ->schema([
                            TextEntry::make('created_at')->label('When')->dateTime(),
                            TextEntry::make('rule_key')->label('Rule')->badge()->color('warning'),
                            TextEntry::make('stage')->badge(),
                            TextEntry::make('action_taken')->label('Action')->badge(),
                            TextEntry::make('end_user_id')->label('End user')->placeholder('—'),
                            KeyValueEntry::make('context')->label('Inputs the rule saw')->columnSpanFull(),
                        ])
                        ->placeholder('No rule fired on this conversion or the click behind it.'),
                ]),

            Section::make('Delivery attempts')
                ->schema([
                    RepeatableEntry::make('outgoingPostbacks')
                        ->label('')
                        ->columns(5)
                        ->schema([
                            TextEntry::make('kind')->badge(),
                            TextEntry::make('attempt'),
                            TextEntry::make('http_status')->label('HTTP')->placeholder('—'),
                            TextEntry::make('duration_ms')->label('Duration')->suffix(' ms')->placeholder('—'),
                            TextEntry::make('status')->badge(),
                            TextEntry::make('url')->label('URL')->columnSpanFull(),
                            TextEntry::make('response_body')->label('Response')->placeholder('—')->columnSpanFull(),
                        ])
                        ->placeholder('Nothing sent — this app has no callback URL.'),
                ]),
        ]);
    }

    /**
     * The rules that judged this conversion — docs/07 §6.
     *
     * Matched on the click as well as on the conversion, and that is the half
     * that actually returns rows: the post-conversion stage runs *before* the
     * conversion is inserted (ConversionProcessor::evaluateFraudRules), so
     * FraudContext::subject() falls back to the click and every event on the
     * money path hangs off it. Querying only the conversion morph finds
     * nothing, which reads exactly like "no rule fired".
     *
     * @return Collection<int, FraudEvent>
     */
    private function fraudEvents(Conversion $record): Collection
    {
        return $this->fraudEventCache ??= FraudEvent::query()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $inner) => $inner
                    ->where('subject_type', $record->getMorphClass())
                    ->where('subject_id', $record->id))
                ->orWhere(fn (Builder $inner) => $inner
                    ->where('subject_type', (new OfferClick)->getMorphClass())
                    ->where('subject_id', $record->offer_click_id)))
            ->orderBy('created_at')
            ->get();
    }

    /**
     * The raw query the network sent, flattened. Reading it beats reasoning
     * about what we parsed out of it.
     *
     * @return array<string, mixed>
     */
    private static function rawPostback(Conversion $record): array
    {
        $log = IncomingPostback::query()
            ->where('conversion_id', $record->id)
            ->latest('id')
            ->first();

        if ($log === null) {
            return ['(none)' => 'No incoming postback is linked to this conversion.'];
        }

        return [
            'received' => (string) $log->created_at,
            'ip' => (string) $log->ip,
            'result' => $log->result?->value ?? '—',
            'signature_valid' => $log->signature_valid === null ? '—' : ($log->signature_valid ? 'yes' : 'no'),
            ...collect($log->query ?? [])->mapWithKeys(fn (mixed $value, string $key): array => ["query.{$key}" => $value])->all(),
        ];
    }
}
