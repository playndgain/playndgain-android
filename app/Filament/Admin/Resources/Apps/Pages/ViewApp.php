<?php

namespace App\Filament\Admin\Resources\Apps\Pages;

use App\Filament\Admin\Resources\Apps\AppResource;
use App\Filament\Admin\Resources\Publishers\PublisherResource;
use App\Models\App;
use App\Models\Network;
use App\Support\AppNetworks;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ViewApp extends ViewRecord
{
    protected static string $resource = AppResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            ActionGroup::make(AppResource::managementActions()),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Placement')->columns(4)->schema([
                TextEntry::make('app_id')->label('App ID')->prefix('#')->copyable(),
                TextEntry::make('name'),
                TextEntry::make('publisher.user.name')
                    ->label('Publisher')
                    ->url(fn (App $record): ?string => $record->publisher_id === null
                        ? null
                        : PublisherResource::getUrl('view', ['record' => $record->publisher_id])),
                TextEntry::make('platform')->badge(),
                TextEntry::make('status')->badge(),
                TextEntry::make('url')->label('Website')->placeholder('—')->copyable(),
                TextEntry::make('approved_at')->dateTime()->placeholder('—'),
                TextEntry::make('created_at')->dateTime(),
                ImageEntry::make('icon_path')
                    ->label('Icon')
                    ->disk('public')
                    ->visible(fn (App $record): bool => filled($record->icon_path)),
            ]),

            Section::make('Integration')->columns(4)->schema([
                TextEntry::make('secret_key')
                    ->label('Secret key')
                    ->state(fn (App $record): string => Str::mask((string) $record->secret_key, '*', 4)),
                IconEntry::make('require_signed_wall')->label('Signed wall')->boolean(),
                IconEntry::make('test_mode')->label('Test mode')->boolean(),
                IconEntry::make('anonymize_ip')->label('Anonymise IPs')->boolean(),
            ]),

            Section::make('Currency & postback')->columns(4)->schema([
                TextEntry::make('currency_name'),
                TextEntry::make('currency_rate')->label('Units per $1'),
                TextEntry::make('postback_url')
                    ->label('Callback URL')
                    ->placeholder('Not configured')
                    ->copyable()
                    ->columnSpan(2),
                TextEntry::make('postback_method')->label('Method')->badge(),
            ]),

            Section::make('Appearance')->columns(4)->schema([
                TextEntry::make('theme.primary')->label('Primary')->placeholder('—'),
                TextEntry::make('theme.background')->label('Background')->placeholder('—'),
                TextEntry::make('theme.text')->label('Text')->placeholder('—'),
                TextEntry::make('theme.radius')->label('Radius')->placeholder('—'),
                IconEntry::make('theme.dark')->label('Dark wall')->boolean(),
            ]),

            Section::make('Restrictions')->columns(4)->schema([
                TextEntry::make('blocked_countries')->badge()->placeholder('None')->columnSpan(2),
                TextEntry::make('daily_conversion_cap')->label('Daily cap')->placeholder('Uncapped'),
                TextEntry::make('disabled_networks')
                    ->label('Disabled networks')
                    ->badge()
                    ->placeholder('All on')
                    ->state(fn (App $record): array => Network::query()
                        ->whereKey(AppNetworks::blockedIds($record))
                        ->orderBy('name')
                        ->pluck('name')
                        ->all())
                    ->columnSpan(2),
            ]),

            Section::make('Last 7 days')->columns(3)->schema([
                TextEntry::make('clicks_7d')
                    ->label('Clicks')
                    ->numeric()
                    ->state(fn (App $record): int => $record->offerClicks()
                        ->where('created_at', '>=', now()->subDays(7))
                        ->count()),
                TextEntry::make('conversions_7d')
                    ->label('Conversions')
                    ->numeric()
                    ->state(fn (App $record): int => $record->conversions()
                        ->where('created_at', '>=', now()->subDays(7))
                        ->count()),
                TextEntry::make('revenue_7d')
                    ->label('Revenue')
                    ->money('USD')
                    ->state(fn (App $record): string => (string) $record->conversions()
                        ->where('created_at', '>=', now()->subDays(7))
                        ->sum('revenue')),
            ]),
        ]);
    }
}
