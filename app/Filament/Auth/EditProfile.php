<?php

namespace App\Filament\Auth;

use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use LogicException;
use SensitiveParameter;

/**
 * Shared profile lock for both panels. Email is shown and not written —
 * changing it from here is an account-takeover path. Each panel says who
 * can change it.
 */
abstract class EditProfile extends BaseEditProfile
{
    /**
     * Stacked labels, same as every other panel form. Filament switches
     * this page to inline labels when it lives in the panel sheet.
     */
    public function defaultForm(Schema $schema): Schema
    {
        return parent::defaultForm($schema)->inlineLabel(false);
    }

    abstract protected function emailLockHelperText(): string;

    /**
     * Shown, copyable, never written. `disabled` is the field; `dehydrated`
     * keeps it out of form state; the forget in `handleRecordUpdate` is the
     * write-path lock if a payload still carries it.
     */
    protected function getEmailFormComponent(): Component
    {
        $email = parent::getEmailFormComponent();

        if (! $email instanceof TextInput) {
            throw new LogicException('Profile email field must be a TextInput.');
        }

        return $email
            ->disabled()
            ->dehydrated(false)
            ->copyable()
            ->helperText($this->emailLockHelperText());
    }

    /**
     * Email cannot change here, so current password is only the gate on a
     * password change. Filament also shows it when the email field differs.
     */
    protected function getCurrentPasswordFormComponent(): Component
    {
        return parent::getCurrentPasswordFormComponent()
            ->visible(fn (Get $get): bool => filled($get('password')));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, #[SensitiveParameter] array $data): Model
    {
        Arr::forget($data, 'email');

        return parent::handleRecordUpdate($record, $data);
    }
}
