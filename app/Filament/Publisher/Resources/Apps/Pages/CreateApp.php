<?php

namespace App\Filament\Publisher\Resources\Apps\Pages;

use App\Enums\AppStatus;
use App\Filament\Publisher\Resources\Apps\AppResource;
use App\Filament\Publisher\Support\CurrentPublisher;
use App\Models\App;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Validation\ValidationException;

class CreateApp extends CreateRecord
{
    use HasWizard;

    protected static string $resource = AppResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    /**
     * A first app is set up in order: each step validates before the next
     * opens, and "Create" is the last step's button. The steps and the
     * preview beside them are the resource's own.
     */
    public function form(Schema $schema): Schema
    {
        return AppResource::wizardForm($schema, $this->getWizardComponent());
    }

    /** @return list<Step> */
    public function getSteps(): array
    {
        return AppResource::steps();
    }

    /**
     * The publisher and the status are ours to set, not the form's. A pending
     * publisher may create apps; those apps stay pending until an admin says
     * otherwise — docs/08 §1.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $publisher = CurrentPublisher::get();

        if ($publisher === null) {
            throw ValidationException::withMessages([
                'name' => 'This login has no publisher account. Sign in as a publisher, or impersonate one from the admin panel.',
            ]);
        }

        $data['publisher_id'] = $publisher->id;
        $data['status'] = AppStatus::Pending;

        return $data;
    }

    /**
     * The secret is displayed in full exactly once, on the screen that created
     * it. Stashing it in the session is what lets the Integration tab show it
     * after the redirect without ever showing it again.
     */
    protected function afterCreate(): void
    {
        /** @var App $app */
        $app = $this->getRecord();

        session()->flash("app-secret.{$app->id}", (string) $app->secret_key);
    }
}
