<?php

namespace App\Filament\Admin\Resources\Apps\Pages;

use App\Filament\Admin\Resources\Apps\AppResource;
use App\Models\App;
use App\Models\AuditLog;
use App\Support\AppNetworks;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

class EditApp extends EditRecord
{
    protected static string $resource = AppResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            Action::make('regenerate_secret')
                ->label('Regenerate secret')
                ->icon(Heroicon::OutlinedKey)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Regenerate the secret key?')
                ->modalDescription('Signed wall URLs and postback signatures that still use the old key stop verifying the moment you confirm.')
                ->action(function (): void {
                    /** @var App $app */
                    $app = $this->getRecord();

                    $secret = Str::random(40);
                    $app->update(['secret_key' => $secret]);

                    session()->flash("app-secret.{$app->id}", $secret);

                    AuditLog::record('app.secret_regenerated', $app);

                    Notification::make()
                        ->title('New secret generated')
                        ->body($secret)
                        ->warning()
                        ->persistent()
                        ->send();

                    $this->fillForm();
                }),
            ActionGroup::make(AppResource::managementActions()),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['enabled_networks'] = AppNetworks::enabledIds($this->getRecord());

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var App $app */
        $app = $this->getRecord();

        AppNetworks::sync($app, $this->form->getRawState()['enabled_networks'] ?? null);

        AuditLog::record('app.updated', $app, null, $app->getChanges());
    }
}
