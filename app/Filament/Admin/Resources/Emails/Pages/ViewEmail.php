<?php

namespace App\Filament\Admin\Resources\Emails\Pages;

use App\Filament\Admin\Resources\Emails\EmailResource;
use RickDBCN\FilamentEmail\Filament\Resources\EmailResource\Pages\ViewEmail as VendorViewEmail;

class ViewEmail extends VendorViewEmail
{
    protected static string $resource = EmailResource::class;
}
