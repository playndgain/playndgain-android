<?php

namespace App\Filament\Admin\Resources\AuditLogs;

use App\Enums\AuditActionFamily;
use App\Filament\Admin\Pages\Protection;
use App\Filament\Admin\Pages\Settings;
use App\Filament\Admin\Resources\AdminUsers\AdminUserResource;
use App\Filament\Admin\Resources\Apps\AppResource;
use App\Filament\Admin\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Admin\Resources\Conversions\ConversionResource;
use App\Filament\Admin\Resources\Networks\NetworkResource;
use App\Filament\Admin\Resources\Publishers\PublisherResource;
use App\Filament\Admin\Support\DateRangeFilter;
use App\Filament\Tables\Columns\UserColumn;
use App\Models\AuditLog;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Every admin action touching money, status, or bans — docs/07 §8.
 *
 * Read-only, no delete, ever. An audit log an admin can prune answers no
 * question worth asking.
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|\UnitEnum|null $navigationGroup = 'Logs';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Html::make(fn (AuditLog $record): Htmlable => view('filament.admin.audit-inspect', [
                'record' => $record,
                'subjectUrl' => self::subjectUrl($record),
            ])),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user:id,name,email'))
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->since()
                    ->description(fn (AuditLog $record): ?string => $record->created_at?->timezone(config('app.timezone'))->format('M j, H:i'))
                    ->sortable(),
                UserColumn::make('user')
                    ->label('Actor')
                    ->searchable(),
                TextColumn::make('action')
                    ->label('Event')
                    ->badge()
                    ->icon(fn (AuditLog $record): Heroicon => AuditActionFamily::verbIcon($record->action))
                    ->color(fn (AuditLog $record): string => AuditActionFamily::verbColor($record->action))
                    ->state(fn (AuditLog $record): string => $record->label())
                    ->description(fn (AuditLog $record): string => $record->family()->getLabel())
                    ->searchable(),
                TextColumn::make('subject')
                    ->label('Subject')
                    ->state(fn (AuditLog $record): string => $record->subjectLabel())
                    ->description(fn (AuditLog $record): string => class_basename($record->subject_type))
                    ->url(fn (AuditLog $record): ?string => self::subjectUrl($record))
                    ->color(fn (AuditLog $record): string => self::subjectUrl($record) !== null ? 'primary' : 'gray')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('subject_type', 'like', "%{$search}%")
                        ->orWhere('subject_id', $search)),
                ViewColumn::make('after')
                    ->label('Change')
                    ->view('filament.tables.columns.audit-change'),
                TextColumn::make('ip')
                    ->label('IP')
                    ->placeholder('—')
                    ->copyable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('action')->options(fn (): array => AuditLog::query()
                    ->distinct()
                    ->orderBy('action')
                    ->pluck('action', 'action')
                    ->all()),
                SelectFilter::make('user_id')->label('Actor')->relationship('user', 'name')->searchable(),
                SelectFilter::make('subject_type')
                    ->label('Subject')
                    ->options(fn (): array => AuditLog::query()
                        ->distinct()
                        ->orderBy('subject_type')
                        ->pluck('subject_type')
                        ->mapWithKeys(fn (string $type): array => [$type => class_basename($type)])
                        ->all()),
                DateRangeFilter::make(),
            ])
            ->recordActions([
                Action::make('inspect')
                    ->label('Inspect')
                    ->icon(Heroicon::OutlinedEye)
                    ->slideOver()
                    ->modalWidth(Width::TwoExtraLarge)
                    ->modalHeading(fn (AuditLog $record): string => $record->label())
                    ->modalContent(fn (AuditLog $record) => view('filament.admin.audit-inspect', [
                        'record' => $record->loadMissing('user:id,name,email'),
                        'subjectUrl' => self::subjectUrl($record),
                    ])),
            ])
            ->recordAction('inspect')
            ->recordClasses(fn (AuditLog $record): string => 'ad-audit-row ad-audit-row--'.$record->family()->value)
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('No audit events')
            ->emptyStateDescription('Money, status, and access actions land here.')
            ->emptyStateIcon(Heroicon::OutlinedClipboardDocumentList);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditLogs::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function subjectUrl(AuditLog $record): ?string
    {
        if (str_starts_with($record->action, 'settings.')) {
            return Settings::getUrl();
        }

        if (str_starts_with($record->action, 'ip_provider.')) {
            return Protection::getUrl();
        }

        $basename = class_basename($record->subject_type);

        try {
            return match ($basename) {
                'Publisher' => PublisherResource::getUrl('view', ['record' => $record->subject_id]),
                'App' => AppResource::getUrl('view', ['record' => $record->subject_id]),
                'Conversion' => ConversionResource::getUrl('view', ['record' => $record->subject_id]),
                'Network' => NetworkResource::getUrl('edit', ['record' => $record->subject_id]),
                'User' => AdminUserResource::getUrl('index'),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }
}
