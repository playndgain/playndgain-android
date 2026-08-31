<?php

namespace App\Filament\Admin\Resources\AdminUsers;

use App\Enums\UserRole;
use App\Filament\Admin\Resources\AdminUsers\Pages\ListAdminUsers;
use App\Filament\Support\ImpersonateAction;
use App\Models\AuditLog;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Admin accounts — docs/07 §9.
 *
 * 2FA status is shown per user because the panel requires it (config-gated for
 * local development). An admin without it is an admin who cannot get in, and
 * seeing that here beats finding out from a support message.
 */
class AdminUserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Admin users';

    protected static ?string $recordTitleAttribute = 'name';

    /** Admins only. The publishers table is its own resource with its own rules. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('role', UserRole::Admin);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(191),
                TextInput::make('email')->email()->required()->unique(ignoreRecord: true)->maxLength(191),
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->minLength(12)
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->helperText('Leave blank to keep the current password.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->copyable(),
                IconColumn::make('two_factor')->label('2FA')->boolean()
                    ->state(fn (User $record): bool => $record->hasConfirmedTwoFactor()),
                TextColumn::make('last_login_at')->label('Last login')->dateTime()->placeholder('never'),
                TextColumn::make('last_login_ip')->label('Last IP')->placeholder('—'),
                TextColumn::make('created_at')->label('Created')->date()->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                ImpersonateAction::make('admin.impersonated'),
                Action::make('reset_2fa')
                    ->label('Reset 2FA')
                    ->icon(Heroicon::OutlinedKey)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('They will be asked to set up an authenticator again on next sign-in.')
                    ->visible(fn (User $record): bool => $record->hasConfirmedTwoFactor())
                    ->action(function (User $record): void {
                        $record->saveAppAuthenticationSecret(null);
                        $record->saveAppAuthenticationRecoveryCodes(null);

                        AuditLog::record('admin.2fa_reset', $record);
                    }),
            ])
            ->toolbarActions([])
            ->headerActions([
                CreateAction::make()->mutateDataUsing(function (array $data): array {
                    $data['role'] = UserRole::Admin;

                    return $data;
                }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAdminUsers::route('/'),
        ];
    }

    public static function canDelete(Model $record): bool
    {
        // Deleting yourself locks the panel behind an account that no longer
        // exists. Every other admin can still remove you.
        return $record->getKey() !== Auth::id();
    }
}
