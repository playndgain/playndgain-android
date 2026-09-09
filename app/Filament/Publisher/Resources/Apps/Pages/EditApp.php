<?php

namespace App\Filament\Publisher\Resources\Apps\Pages;

use App\Filament\Publisher\Resources\Apps\AppResource;
use App\Filament\Publisher\Support\PublisherTestPostbackAction;
use App\Models\App;
use App\Models\AuditLog;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Illuminate\Support\Str;

class EditApp extends EditRecord
{
    protected static string $resource = AppResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function getHeaderActions(): array
    {
        return [
            PublisherTestPostbackAction::make(),
            Action::make('regenerate_secret')
                ->label('Regenerate secret')
                ->icon('lucide-key-round')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Regenerate the secret key?')
                ->modalDescription('Every signature you already compute with the old key stops verifying the moment you confirm. Signed wall URLs and postback signatures both break until you deploy the new one.')
                ->action(function (): void {
                    /** @var App $app */
                    $app = $this->getRecord();

                    $secret = Str::random(40);
                    $app->update(['secret_key' => $secret]);

                    // Same one-shot reveal as creation: flashed for this render
                    // and never readable again.
                    session()->flash("app-secret.{$app->id}", $secret);

                    AuditLog::record('app.secret_regenerated', $app);

                    Notification::make()
                        ->title('New secret generated')
                        ->body('Copy it from the Integration tab now — it is shown once.')
                        ->warning()
                        ->send();

                    $this->fillForm();
                }),
        ];
    }
}
