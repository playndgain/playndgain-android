<?php

namespace App\Filament\Admin\Resources\LedgerEntries;

use App\Enums\LedgerEntryType;
use App\Filament\Admin\Resources\LedgerEntries\Pages\ListLedgerEntries;
use App\Filament\Admin\Support\DateRangeFilter;
use App\Models\LedgerEntry;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Count;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every entry, every publisher — docs/07 §6.
 *
 * No create, no edit, no delete, and no bulk actions. The model throws on all
 * three anyway; a panel that offers what the model forbids just produces a
 * stack trace instead of a refusal.
 */
class LedgerEntryResource extends Resource
{
    protected static ?string $model = LedgerEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|\UnitEnum|null $navigationGroup = 'Money';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Ledger';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['publisher.user:id,name', 'creator:id,name']))
            ->columns([
                TextColumn::make('created_at')->label('Date')->dateTime()->sortable(),
                TextColumn::make('publisher.pub_id')->label('Publisher')->prefix('#')
                    ->description(fn (LedgerEntry $record): string => (string) $record->publisher?->user?->name),
                TextColumn::make('type')->badge()->color(fn (LedgerEntryType $state): string => match ($state) {
                    LedgerEntryType::Earning, LedgerEntryType::Referral => 'success',
                    LedgerEntryType::Chargeback => 'danger',
                    LedgerEntryType::Withdrawal => 'warning',
                    LedgerEntryType::Adjustment => 'info',
                }),
                TextColumn::make('amount')->money('USD')->sortable()
                    ->color(fn (string $state): string => (float) $state < 0 ? 'danger' : 'success')
                    // Grouped by publisher and filtered to `chargeback`, this
                    // sum is what that publisher owes back over the window.
                    ->summarize([
                        Count::make()->label('Entries'),
                        Sum::make()->money('USD'),
                    ]),
                TextColumn::make('balance_after')->label('Balance after')->money('USD'),
                TextColumn::make('reference_type')->label('Reference')
                    ->formatStateUsing(fn (?string $state, LedgerEntry $record): string => $state === null
                        ? '—'
                        : class_basename($state).' #'.$record->reference_id),
                TextColumn::make('description')->wrap()->limit(60)->searchable(),
                TextColumn::make('creator.name')->label('By')->placeholder('system'),
            ])
            ->groups([
                Group::make('publisher.company_name')->label('Publisher'),
                Group::make('type')->label('Type'),
                Group::make('created_at')->label('Date')->date(),
            ])
            ->filters([
                SelectFilter::make('publisher_id')->label('Publisher')->relationship('publisher', 'company_name')->searchable(),
                SelectFilter::make('type')->options(LedgerEntryType::class),
                DateRangeFilter::make(),
                Filter::make('amount')
                    ->schema([TextInput::make('min')->numeric(), TextInput::make('max')->numeric()])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(filled($data['min'] ?? null), fn (Builder $q) => $q->where('amount', '>=', $data['min']))
                        ->when(filled($data['max'] ?? null), fn (Builder $q) => $q->where('amount', '<=', $data['max']))),
            ])
            ->recordActions([])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLedgerEntries::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
