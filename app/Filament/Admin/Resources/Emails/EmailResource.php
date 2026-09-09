<?php

namespace App\Filament\Admin\Resources\Emails;

use App\Filament\Admin\Resources\Emails\Pages\ListEmails;
use App\Filament\Admin\Resources\Emails\Pages\ViewEmail;
use App\Filament\Admin\Support\DateRangeFilter;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RickDBCN\FilamentEmail\Filament\Resources\EmailResource as VendorEmailResource;
use RickDBCN\FilamentEmail\Filament\Resources\EmailResource\Actions\AdvancedResendEmailAction;
use RickDBCN\FilamentEmail\Filament\Resources\EmailResource\Actions\ResendEmailAction;
use RickDBCN\FilamentEmail\Filament\Resources\EmailResource\Actions\ResendEmailBulkAction;
use RickDBCN\FilamentEmail\Filament\Resources\EmailResource\Actions\ViewEmailAction;
use RickDBCN\FilamentEmail\Models\Email;

class EmailResource extends VendorEmailResource
{
    protected static ?string $slug = 'emails';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 80;

    public static function getBreadcrumb(): string
    {
        return 'Sent mail';
    }

    public static function getNavigationLabel(): string
    {
        return 'Sent mail';
    }

    public static function getModelLabel(): string
    {
        return 'Email';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Sent mail';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort(
                config('filament-email.resource.default_sort_column', 'created_at'),
                config('filament-email.resource.default_sort_direction', 'desc'),
            )
            ->actions([
                ViewEmailAction::make(),
                ResendEmailAction::make(),
                AdvancedResendEmailAction::make(),
            ])
            ->columns([
                TextColumn::make('from')
                    ->prefix(__('filament-email::filament-email.from').': ')
                    ->suffix(fn (Email $record): string => ! empty($record->attachments) ? ' ('.trans_choice('filament-email::filament-email.attachments_number', count($record->attachments)).')' : '')
                    ->label(__('filament-email::filament-email.header'))
                    ->description(fn (Email $record): string => Str::limit(__('filament-email::filament-email.to').': '.$record->to, 40))
                    ->searchable(),
                TextColumn::make('subject')
                    ->label(__('filament-email::filament-email.subject'))
                    ->limit(50),
                TextColumn::make('created_at')
                    ->label(__('filament-email::filament-email.sent_at'))
                    ->dateTime(config('filament-email.resource.datetime_format'))
                    ->sortable(),
            ])
            ->groupedBulkActions([
                ResendEmailBulkAction::make(),
                DeleteBulkAction::make()
                    ->requiresConfirmation(),
            ])
            ->persistFiltersInSession()
            ->filtersFormColumns([
                'default' => 1,
                'sm' => 2,
                'lg' => 3,
            ])
            ->filters([
                Filter::make('to')
                    ->label('To')
                    ->schema([
                        TextInput::make('email')
                            ->label('To')
                            ->placeholder('Recipient address'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $email = $data['email'] ?? null;
                        if (! filled($email)) {
                            return $query;
                        }

                        return $query->where(function (Builder $q) use ($email) {
                            $q->where('to', 'like', "%{$email}%")
                                ->orWhere('cc', 'like', "%{$email}%")
                                ->orWhere('bcc', 'like', "%{$email}%");
                        });
                    })
                    ->indicateUsing(fn (array $data): ?string => filled($data['email'] ?? null) ? 'To: '.$data['email'] : null),

                Filter::make('subject')
                    ->label('Subject')
                    ->schema([
                        TextInput::make('search')
                            ->label('Subject')
                            ->placeholder('Subject line'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['search'] ?? null)
                        ? $query->where('subject', 'like', '%'.$data['search'].'%')
                        : $query)
                    ->indicateUsing(fn (array $data): ?string => filled($data['search'] ?? null) ? 'Subject: '.$data['search'] : null),

                TernaryFilter::make('attachments')
                    ->label('Attachments')
                    ->placeholder('All')
                    ->trueLabel('With attachments')
                    ->falseLabel('Without attachments')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('attachments')->where(DB::raw('JSON_LENGTH(attachments)'), '>', 0),
                        false: fn (Builder $query): Builder => $query->where(fn (Builder $q) => $q->whereNull('attachments')->orWhere(DB::raw('JSON_LENGTH(attachments)'), 0)),
                        blank: fn (Builder $query): Builder => $query,
                    ),

                DateRangeFilter::make('created_at', 'sent'),
            ])
            ->paginationPageOptions(function (Table $table) {
                $options = config('filament-email.pagination_page_options');

                return ! empty($options) && is_array($options) ? $options : $table->getPaginationPageOptions();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmails::route('/'),
            'view' => ViewEmail::route('/{record}'),
        ];
    }
}
