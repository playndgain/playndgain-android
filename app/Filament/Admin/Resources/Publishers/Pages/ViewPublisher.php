<?php

namespace App\Filament\Admin\Resources\Publishers\Pages;

use App\Enums\ConversionStatus;
use App\Filament\Admin\Resources\Publishers\PublisherResource;
use App\Models\Publisher;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewPublisher extends ViewRecord
{
    protected static string $resource = PublisherResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Profile')->columns(4)->schema([
                TextEntry::make('pub_id')->label('Pub ID')->prefix('#'),
                TextEntry::make('user.name')->label('Name'),
                TextEntry::make('user.email')->label('Email')->copyable(),
                TextEntry::make('country')->placeholder('—'),
                TextEntry::make('status')->badge(),
                TextEntry::make('rev_share')->label('Rev share')->suffix('%'),
                TextEntry::make('min_payout')->label('Min payout')->money('USD'),
            ]),

            Section::make('Balances')->columns(4)->schema([
                TextEntry::make('available')->money('USD')
                    ->state(fn (Publisher $record): string => $record->withdrawableBalance())
                    ->helperText('Cleared minus open requests'),
                TextEntry::make('pending')->money('USD')
                    ->state(fn (Publisher $record): string => $record->pendingBalance())
                    ->helperText('Still inside the hold window'),
                TextEntry::make('balance')->label('Cached balance')->money('USD'),
                TextEntry::make('lifetime_earnings')->label('Lifetime')->money('USD'),
            ]),

            Section::make('Risk')->columns(4)->schema([
                TextEntry::make('reversal_rate')->label('Reversal rate')
                    ->state(fn (Publisher $record): string => self::rate(
                        $record->conversions()->where('status', ConversionStatus::Reversed)->count(),
                        $record->conversions()->count(),
                    )),
                TextEntry::make('flag_rate')->label('In review')
                    ->state(fn (Publisher $record): string => self::rate(
                        $record->conversions()->where('status', ConversionStatus::Pending)->count(),
                        $record->conversions()->count(),
                    )),
                TextEntry::make('conversion_rate')->label('Conversion rate')
                    ->state(fn (Publisher $record): string => self::rate(
                        $record->conversions()->count(),
                        $record->apps()->withCount('offerClicks')->get()->sum('offer_clicks_count'),
                    )),
                TextEntry::make('account_age')->label('Account age')
                    ->state(fn (Publisher $record): string => $record->created_at->diffForHumans()),
            ]),

            Section::make('Traffic declaration')->schema([
                TextEntry::make('traffic_source')->label('')->placeholder('Not declared')->prose(),
            ]),

            Section::make('Admin notes')->schema([
                TextEntry::make('notes')->label('')->placeholder('None')->prose(),
            ]),
        ]);
    }

    private static function rate(int $part, int $whole): string
    {
        return $whole === 0 ? '—' : number_format($part / $whole * 100, 2).'%';
    }
}
