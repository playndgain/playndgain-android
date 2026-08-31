<?php

namespace App\Filament\Admin\Resources\Apps\RelationManagers;

use App\Enums\FraudActionTaken;
use App\Enums\FraudStage;
use App\Filament\Admin\Resources\FraudEvents\FraudEventResource;
use App\Models\FraudEvent;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FraudEventsRelationManager extends RelationManager
{
    protected static string $relationship = 'fraudEvents';

    protected static ?string $title = 'Fraud events';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('rule_key')
            ->columns([
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('rule_key')->label('Rule')->searchable()->badge(),
                TextColumn::make('stage')->badge(),
                TextColumn::make('action_taken')->label('Action')->badge()
                    ->color(fn (FraudActionTaken $state): string => match ($state) {
                        FraudActionTaken::Blocked => 'danger',
                        FraudActionTaken::Flagged => 'warning',
                        FraudActionTaken::Scored => 'gray',
                    }),
                TextColumn::make('subject')->label('Subject')
                    ->state(fn (FraudEvent $record): string => class_basename($record->subject_type).' #'.$record->subject_id),
                TextColumn::make('end_user_id')->label('End user')->placeholder('—')->searchable()->limit(20),
                TextColumn::make('context_summary')->label('Context')
                    ->state(fn (FraudEvent $record): string => is_string($record->context['reason'] ?? null)
                        ? $record->context['reason']
                        : '—')
                    ->wrap()
                    ->limit(80),
            ])
            ->filters([
                SelectFilter::make('stage')->options(FraudStage::class),
                SelectFilter::make('action_taken')->label('Action')->options(FraudActionTaken::class),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->recordActions([
                ViewAction::make()
                    ->slideOver()
                    ->infolist(fn (Schema $schema): Schema => FraudEventResource::infolist($schema)),
            ])
            ->toolbarActions([]);
    }
}
