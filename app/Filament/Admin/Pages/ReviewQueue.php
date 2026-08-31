<?php

namespace App\Filament\Admin\Pages;

use App\Enums\ConversionStatus;
use App\Filament\Admin\Resources\Conversions\ConversionResource;
use App\Models\Conversion;
use App\Models\FraudEvent;
use App\Models\OfferClick;
use App\Services\Money\ConversionProcessor;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Conversions a `flag` rule held back — docs/07 §7.
 *
 * Approve writes the ledger entry and fires the postback; reject records the
 * reason and moves nothing. Both go through ConversionProcessor and both are
 * audit-logged there.
 */
class ReviewQueue extends Page implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static string|\UnitEnum|null $navigationGroup = 'Risk';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Review queue';

    protected string $view = 'filament.admin.pages.review-queue';

    public static function getNavigationBadge(): ?string
    {
        $pending = Conversion::query()->withoutGlobalScopes()
            ->where('status', ConversionStatus::Pending)
            ->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Conversion::query()
                ->withoutGlobalScopes()
                ->with(['publisher:id,pub_id', 'app:id,name', 'network:id,name', 'offerClick'])
                ->where('status', ConversionStatus::Pending))
            ->columns([
                TextColumn::make('trans_id')->label('TX')->copyable()->limit(16),
                TextColumn::make('publisher.pub_id')->label('Pub')->prefix('#'),
                TextColumn::make('app.name')->label('App')->limit(18),
                TextColumn::make('offer_name')->label('Offer')->limit(24),
                TextColumn::make('payout')->money('USD'),
                TextColumn::make('rules')
                    ->label('Fired rules')
                    ->state(fn (Conversion $record): string => $this->firedRules($record))
                    ->badge()
                    ->color('warning')
                    ->wrap(),
                TextColumn::make('click')
                    ->label('Click')
                    ->state(fn (Conversion $record): string => $record->offerClick === null
                        ? '—'
                        : sprintf(
                            '%s · %s · %s',
                            $record->offerClick->ip,
                            $record->offerClick->country ?? '??',
                            $record->offerClick->device_type->value,
                        ))
                    // ponytail: no proxy/datacenter/ASN verdict here. The IP
                    // intelligence it would read lands with the fraud engine in
                    // phase 3; the raw IP is shown so the decision is not blind.
                    ->tooltip('Proxy and ASN verdicts arrive with the fraud engine'),
                TextColumn::make('history')
                    ->label('User history')
                    ->state(fn (Conversion $record): string => $this->endUserHistory($record)),
                TextColumn::make('created_at')->label('Received')->since()->sortable(),
            ])
            ->recordActions([
                Action::make('approve')
                    ->icon(Heroicon::OutlinedCheckBadge)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Credits the publisher and fires their postback.')
                    ->action(fn (Conversion $record) => app(ConversionProcessor::class)->approvePending($record, Auth::user())),
                Action::make('reject')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->schema([Textarea::make('reason')->required()->rows(2)->maxLength(191)])
                    ->action(fn (Conversion $record, array $data) => app(ConversionProcessor::class)
                        ->rejectPending($record, $data['reason'], Auth::user())),
                Action::make('detail')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (Conversion $record): string => ConversionResource::getUrl('view', ['record' => $record])),
            ])
            ->defaultSort('created_at', 'asc')
            ->emptyStateHeading('Queue is empty')
            ->emptyStateDescription('No conversion is waiting on a decision.');
    }

    /**
     * The rules that actually held this row.
     *
     * Matched on the click as well as on the conversion, and the click is the
     * half that returns rows: the post-conversion stage runs *before* the
     * conversion is inserted (ConversionProcessor::evaluateFraudRules), so
     * FraudContext::subject() falls back to the click. Querying the conversion
     * morph alone found nothing and rendered "none recorded" — on the one
     * column this page exists for.
     */
    private function firedRules(Conversion $conversion): string
    {
        $keys = FraudEvent::query()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $inner) => $inner
                    ->where('subject_type', $conversion->getMorphClass())
                    ->where('subject_id', $conversion->id))
                ->orWhere(fn (Builder $inner) => $inner
                    ->where('subject_type', (new OfferClick)->getMorphClass())
                    ->where('subject_id', $conversion->offer_click_id)))
            ->pluck('rule_key')
            ->unique();

        return $keys->isEmpty() ? 'none recorded' : $keys->implode(', ');
    }

    /** What this end user has done on this app before. Repeat offenders show here. */
    private function endUserHistory(Conversion $conversion): string
    {
        $total = Conversion::query()
            ->withoutGlobalScopes()
            ->where('app_id', $conversion->app_id)
            ->where('end_user_id', $conversion->end_user_id)
            ->count();

        $reversed = Conversion::query()
            ->withoutGlobalScopes()
            ->where('app_id', $conversion->app_id)
            ->where('end_user_id', $conversion->end_user_id)
            ->where('status', ConversionStatus::Reversed)
            ->count();

        return "{$total} conversions · {$reversed} reversed";
    }
}
