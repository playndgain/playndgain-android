<?php

namespace App\Filament\Admin\Resources\Publishers\Pages;

use App\Filament\Admin\Resources\Publishers\PublisherResource;
use App\Models\AuditLog;
use App\Models\Publisher;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\Rules\Password;
use SensitiveParameter;

class EditPublisher extends EditRecord
{
    protected static string $resource = PublisherResource::class;

    /**
     * Password and `email_verified_at` live on the user row. Saving this form
     * never writes them, so they are header actions — same reason regenerate
     * secret is not a field on the app form.
     */
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            Action::make('change_password')
                ->label('Change password')
                ->icon(Heroicon::OutlinedKey)
                ->color('warning')
                ->modalSubmitActionLabel('Change password')
                ->schema([
                    TextInput::make('password')
                        ->password()
                        ->revealable()
                        ->required()
                        ->rule(Password::min(10)->letters()->numbers())
                        ->same('password_confirmation')
                        ->helperText('At least 10 characters. Long beats complicated.'),
                    TextInput::make('password_confirmation')
                        ->label('Confirm password')
                        ->password()
                        ->revealable()
                        ->required(),
                ])
                ->action(function (#[SensitiveParameter] array $data): void {
                    $user = $this->recordUser();

                    if (! $user instanceof User) {
                        return;
                    }

                    $user->update(['password' => $data['password']]);

                    AuditLog::record('publisher.password_changed', $this->getRecord());

                    Notification::make()->title('Password updated')->success()->send();
                }),
            Action::make('verify_account')
                ->label('Verify account')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Verify this account?')
                ->modalDescription('They can reach the panel without opening the mailed link. Same as them confirming the address themselves.')
                ->visible(fn (): bool => ! $this->recordUser()?->hasVerifiedEmail())
                ->action(function (): void {
                    $user = $this->recordUser();

                    if (! $user instanceof User || $user->hasVerifiedEmail()) {
                        return;
                    }

                    $user->markEmailAsVerified();

                    AuditLog::record('publisher.email_verified', $this->getRecord(), [
                        'email_verified_at' => null,
                    ], [
                        'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                    ]);

                    Notification::make()->title('Account verified')->success()->send();
                }),
        ];
    }

    /** Terms changes are money changes. They are logged like every other one. */
    protected function afterSave(): void
    {
        /** @var Publisher $publisher */
        $publisher = $this->getRecord();

        AuditLog::record('publisher.updated', $publisher, null, $publisher->getChanges());
    }

    private function recordUser(): ?User
    {
        $user = $this->getRecord()->user;

        return $user instanceof User ? $user : null;
    }
}
