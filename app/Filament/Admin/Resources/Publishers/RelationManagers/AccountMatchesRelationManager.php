<?php

namespace App\Filament\Admin\Resources\Publishers\RelationManagers;

use App\Enums\IdentityKind;
use App\Models\AccountMatch;
use App\Models\Publisher;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AccountMatchesRelationManager extends RelationManager
{
    protected static string $relationship = 'accountMatches';

    protected static ?string $title = 'Linked accounts';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query): Builder {
                $owner = $this->getOwnerRecord();

                if (! $owner instanceof Publisher) {
                    return $query;
                }

                return $query->getModel()->newQuery()
                    ->with(['leftUser:id,name,email', 'rightUser:id,name,email'])
                    ->where(function (Builder $inner) use ($owner): void {
                        $inner->where('publisher_id', $owner->id)
                            ->orWhere('left_user_id', $owner->user_id)
                            ->orWhere('right_user_id', $owner->user_id);
                    });
            })
            ->columns([
                TextColumn::make('last_seen_at')->label('Last seen')->dateTime()->sortable(),
                TextColumn::make('scope')->badge(),
                ViewColumn::make('signal')
                    ->label('Signal')
                    ->view('filament.tables.columns.identity-signal'),
                TextColumn::make('left_label')
                    ->label('Left')
                    ->state(fn (AccountMatch $record): string => $record->scope === IdentityKind::Panel
                        ? (string) ($record->leftUser?->email ?: $record->left_user_id)
                        : (string) ($record->left_end_user_id ?: '—'))
                    ->copyable()
                    ->fontFamily(FontFamily::Mono),
                TextColumn::make('right_label')
                    ->label('Right')
                    ->state(fn (AccountMatch $record): string => $record->scope === IdentityKind::Panel
                        ? (string) ($record->rightUser?->email ?: $record->right_user_id)
                        : (string) ($record->right_end_user_id ?: '—'))
                    ->copyable()
                    ->fontFamily(FontFamily::Mono),
                TextColumn::make('seen_count')->label('Count')->numeric()->alignEnd(),
            ])
            ->recordActions([
                ViewAction::make()->slideOver(),
            ])
            ->defaultSort('last_seen_at', 'desc')
            ->headerActions([])
            ->toolbarActions([]);
    }
}
