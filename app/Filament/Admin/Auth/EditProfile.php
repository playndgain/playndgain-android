<?php

namespace App\Filament\Admin\Auth;

use App\Filament\Auth\EditProfile as BaseEditProfile;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Admin profile. Same lock as the publisher page: name and password here,
 * email only from Admin users, and never your own.
 */
class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Profile')
                ->columns(2)
                ->schema([
                    $this->getNameFormComponent(),
                    $this->getEmailFormComponent(),
                ]),
            Section::make('Password')
                ->schema([
                    $this->getPasswordFormComponent(),
                    $this->getPasswordConfirmationFormComponent(),
                    $this->getCurrentPasswordFormComponent(),
                ]),
        ]);
    }

    protected function emailLockHelperText(): string
    {
        return 'This is the login. Another admin changes it under Admin users.';
    }
}
