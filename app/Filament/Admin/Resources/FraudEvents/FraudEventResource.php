<?php

namespace App\Filament\Admin\Resources\FraudEvents;

use App\Enums\FraudActionTaken;
use App\Enums\FraudStage;
use App\Filament\Admin\Resources\Apps\AppResource;
use App\Filament\Admin\Resources\Conversions\ConversionResource;
use App\Filament\Admin\Resources\FraudEvents\Pages\ListFraudEvents;
use App\Filament\Admin\Resources\Publishers\PublisherResource;
use App\Filament\Admin\Support\DateRangeFilter;
use App\Filament\Support\AppColumns;
use App\Models\FraudEvent;
use App\Models\OfferClick;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/** Everything the fraud pipeline decided, and what it saw — docs/07 §7. */
class FraudEventResource extends Resource
{
    protected static ?string $model = FraudEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|\UnitEnum|null $navigationGroup = 'Risk';

    protected static ?int $navigationSort = 3;

    public static function getRecordTitle(?Model $record): string
    {
        if (! $record instanceof FraudEvent) {
            return 'Fraud event';
        }

        return $record->rule_key.' · '.class_basename($record->subject_type).' #'.$record->subject_id;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Decision')->columns(4)->schema([
                TextEntry::make('rule_key')->label('Rule')->badge(),
                TextEntry::make('stage')->badge(),
                TextEntry::make('action_taken')->label('Action')->badge()
                    ->color(fn (FraudActionTaken $state): string => match ($state) {
                        FraudActionTaken::Blocked => 'danger',
                        FraudActionTaken::Flagged => 'warning',
                        FraudActionTaken::Scored => 'gray',
                    }),
                TextEntry::make('created_at')->label('When')->dateTime(),
                TextEntry::make('reason')
                    ->label('Reason')
                    ->state(fn (FraudEvent $record): ?string => is_string($record->context['reason'] ?? null)
                        ? $record->context['reason']
                        : null)
                    ->placeholder('—')
                    ->columnSpanFull(),
            ]),

            Section::make('Who')->columns(3)->schema([
                TextEntry::make('publisher.pub_id')
                    ->label('Publisher')
                    ->prefix('#')
                    ->placeholder('—')
                    ->url(fn (FraudEvent $record): ?string => $record->publisher_id === null
                        ? null
                        : PublisherResource::getUrl('view', ['record' => $record->publisher_id])),
                TextEntry::make('app.name')
                    ->label('App')
                    ->placeholder('—')
                    ->url(fn (FraudEvent $record): ?string => $record->app_id === null
                        ? null
                        : AppResource::getUrl('view', ['record' => $record->app_id])),
                TextEntry::make('end_user_id')
                    ->label('End user')
                    ->placeholder('—')
                    ->copyable(),
            ]),

            Section::make('Subject')->columns(3)->schema([
                TextEntry::make('subject_label')
                    ->label('Record')
                    ->state(fn (FraudEvent $record): string => class_basename($record->subject_type).' #'.$record->subject_id)
                    ->url(fn (FraudEvent $record): ?string => self::subjectUrl($record)),
                TextEntry::make('subject_click_id')
                    ->label('Click ID')
                    ->state(fn (FraudEvent $record): ?string => self::subjectClickId($record))
                    ->placeholder('—')
                    ->copyable(),
                TextEntry::make('subject_ip')
                    ->label('IP')
                    ->state(fn (FraudEvent $record): ?string => is_string($record->context['ip'] ?? null) ? $record->context['ip'] : null)
                    ->placeholder('—')
                    ->copyable(),
            ]),

            Section::make('Inputs the rule saw')
                ->description('Everything recorded at trigger time — the answer to "why did this fire".')
                ->schema([
                    KeyValueEntry::make('context_pairs')
                        ->label('')
                        ->state(fn (FraudEvent $record): array => self::contextPairs($record)),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['publisher:id,pub_id', AppColumns::eager()]))
            ->columns([
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('rule_key')->label('Rule')->searchable()->badge(),
                TextColumn::make('stage')->badge(),
                TextColumn::make('action_taken')->label('Action')->badge()
                    ->color(fn (FraudActionTaken $state): string => match ($state) {
                        FraudActionTaken::Blocked => 'danger',
                        FraudActionTaken::Flagged => 'warning',
                        FraudActionTaken::Scored => 'gray',
                    }),
                TextColumn::make('subject')->label('Subject')
                    ->state(fn (FraudEvent $record): string => class_basename($record->subject_type).' #'.$record->subject_id),
                TextColumn::make('publisher.pub_id')->label('Pub')->prefix('#')->placeholder('—'),
                AppColumns::show()->placeholder('—'),
                TextColumn::make('end_user_id')->label('End user')->placeholder('—')->searchable()->limit(20),
                TextColumn::make('context_summary')->label('Context')
                    ->state(fn (FraudEvent $record): string => is_string($record->context['reason'] ?? null)
                        ? $record->context['reason']
                        : '—')
                    ->wrap()
                    ->limit(80),
            ])
            ->filters([
                SelectFilter::make('rule_key')->label('Rule')->options(fn (): array => FraudEvent::query()
                    ->distinct()
                    ->orderBy('rule_key')
                    ->pluck('rule_key', 'rule_key')
                    ->all()),
                SelectFilter::make('stage')->options(FraudStage::class),
                SelectFilter::make('action_taken')->label('Action')->options(FraudActionTaken::class),
                SelectFilter::make('publisher_id')->label('Publisher')->relationship('publisher', 'company_name')->searchable(),
                SelectFilter::make('app_id')->label('App')->relationship('app', 'name')->searchable(),
                DateRangeFilter::make(),
            ])
            ->recordActions([
                ViewAction::make()->slideOver(),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFraudEvents::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function subjectUrl(FraudEvent $record): ?string
    {
        if ($record->subject_id === 0 || blank($record->subject_type)) {
            return null;
        }

        try {
            return match (class_basename($record->subject_type)) {
                'Conversion' => ConversionResource::getUrl('view', ['record' => $record->subject_id]),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    private static function subjectClickId(FraudEvent $record): ?string
    {
        if (class_basename($record->subject_type) !== 'OfferClick' || $record->subject_id === 0) {
            return null;
        }

        return OfferClick::query()->whereKey($record->subject_id)->value('click_id');
    }

    /**
     * @return array<string, string>
     */
    public static function contextPairs(FraudEvent $record): array
    {
        $context = $record->context ?? [];

        if ($context === []) {
            return ['(empty)' => 'This rule did not record any inputs.'];
        }

        $pairs = collect($context)
            ->map(fn (mixed $value): string => is_scalar($value) || $value === null
                ? (string) ($value ?? '')
                : (json_encode($value) ?: ''))
            ->all();

        if (isset($pairs['reason'])) {
            $reason = $pairs['reason'];
            unset($pairs['reason']);

            return ['reason' => $reason] + $pairs;
        }

        return $pairs;
    }
}
