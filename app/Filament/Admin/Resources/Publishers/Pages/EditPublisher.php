<?php

namespace App\Filament\Admin\Resources\Publishers\Pages;

use App\Filament\Admin\Resources\Publishers\PublisherResource;
use App\Models\AuditLog;
use App\Models\Publisher;
use Filament\Resources\Pages\EditRecord;

class EditPublisher extends EditRecord
{
    protected static string $resource = PublisherResource::class;

    /** Terms changes are money changes. They are logged like every other one. */
    protected function afterSave(): void
    {
        /** @var Publisher $publisher */
        $publisher = $this->getRecord();

        AuditLog::record('publisher.updated', $publisher, null, $publisher->getChanges());
    }
}
