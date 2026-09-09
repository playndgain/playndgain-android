<?php

namespace App\Filament\Admin\Resources\Emails\Pages;

use App\Filament\Admin\Resources\Emails\EmailResource;
use Livewire\Attributes\Url;
use RickDBCN\FilamentEmail\Filament\Resources\EmailResource\Pages\ListEmails as VendorListEmails;

class ListEmails extends VendorListEmails
{
    protected static string $resource = EmailResource::class;

    #[Url]
    public ?array $tableFilters = null;
}
