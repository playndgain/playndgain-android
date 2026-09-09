<?php

namespace App\Filament\Admin\Resources\FraudRules;

use App\Enums\FraudAction;
use App\Enums\FraudStage;
use App\Filament\Admin\Resources\FraudRules\Pages\ListFraudRules;
use App\Models\AuditLog;
use App\Models\FraudEvent;
use App\Models\FraudRule;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Js;

/**
 * The tuning instrument — docs/07 §7.
 *
 * The two trigger-count columns are the point of this page. A rule that never
 * fires is dead weight; a rule firing on 40% of traffic is miscalibrated. Both
 * are invisible without the counts next to the toggle.
 */
class FraudRuleResource extends Resource
{
    protected static ?string $model = FraudRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|\UnitEnum|null $navigationGroup = 'Risk';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    private const DESCRIPTION_LIMIT = 72;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(2)->schema([
                TextInput::make('key')->required()->disabledOn('edit')->maxLength(191),
                TextInput::make('name')->required()->maxLength(191),
                Select::make('stage')->options(FraudStage::class)->required(),
                Select::make('action')->options(FraudAction::class)->required(),
                TextInput::make('score_weight')->numeric()->minValue(0)->maxValue(255)->default(0),
                Toggle::make('enabled')->default(true),
                Textarea::make('description')->required()->rows(3)->columnSpanFull(),
                KeyValue::make('config')
                    ->label('Thresholds')
                    ->helperText('The numbers this rule compares against. Shape is per rule.')
                    ->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $counts24h = self::triggerCounts(1);
        $counts7d = self::triggerCounts(7);

        return $table
            ->columns([
                TextColumn::make('key')->searchable()->copyable(),
                TextColumn::make('name')
                    ->searchable()
                    ->description(fn (FraudRule $record): string => str($record->description)->limit(self::DESCRIPTION_LIMIT))
                    ->extraCellAttributes(function (FraudRule $record): array {
                        $description = $record->description;

                        if (blank($description) || str($description)->length() <= self::DESCRIPTION_LIMIT) {
                            return [];
                        }

                        return [
                            'x-tooltip' => '{
                                content: '.Js::from($description).',
                                theme: $store.theme,
                                allowHTML: false,
                            }',
                        ];
                    }),
                TextColumn::make('stage')->badge(),
                SelectColumn::make('action')->options(FraudAction::class)->rules(['required']),
                ToggleColumn::make('enabled')
                    ->afterStateUpdated(fn (FraudRule $record, bool $state) => AuditLog::record(
                        'fraud_rule.toggled',
                        $record,
                        ['enabled' => ! $state],
                        ['enabled' => $state],
                    )),
                TextColumn::make('triggered_24h')->label('Fired 24h')
                    ->state(fn (FraudRule $record): int => $counts24h[$record->key] ?? 0)
                    ->color(fn (int $state): string => $state === 0 ? 'gray' : 'warning'),
                TextColumn::make('triggered_7d')->label('Fired 7d')
                    ->state(fn (FraudRule $record): int => $counts7d[$record->key] ?? 0),
            ])
            ->filters([
                SelectFilter::make('stage')->options(FraudStage::class),
                SelectFilter::make('action')->options(FraudAction::class),
                TernaryFilter::make('enabled')
                    ->placeholder('All')
                    ->trueLabel('On')
                    ->falseLabel('Off'),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([])
            ->defaultSort('stage');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFraudRules::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        // Rules are code plus config. A row with no implementation behind it
        // would silently never fire, which is worse than not having it.
        return false;
    }

    /**
     * One grouped query per window rather than a subquery per row.
     *
     * Public so the admin Protection page's IP-rules table can call the same
     * definition rather than growing a second query that computes a count
     * this class already owns.
     *
     * @return array<string, int>
     */
    public static function triggerCounts(int $days): array
    {
        /** @var Collection<string, int> $counts */
        $counts = FraudEvent::query()
            ->selectRaw('rule_key, COUNT(*) AS total')
            ->where('created_at', '>=', now()->subDays($days))
            ->groupBy('rule_key')
            ->pluck('total', 'rule_key');

        return $counts->all();
    }
}
