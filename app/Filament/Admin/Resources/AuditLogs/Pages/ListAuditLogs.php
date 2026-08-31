<?php

namespace App\Filament\Admin\Resources\AuditLogs\Pages;

use App\Enums\AuditActionFamily;
use App\Filament\Admin\Resources\AuditLogs\AuditLogResource;
use App\Models\AuditLog;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListAuditLogs extends ListRecords
{
    protected static string $resource = AuditLogResource::class;

    /** @var array<string, int>|null */
    private ?array $familyCounts = null;

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        $counts = $this->familyCounts();

        $tabs = [
            'all' => Tab::make('All')
                ->badge(array_sum($counts) ?: null),
        ];

        foreach (AuditActionFamily::cases() as $family) {
            $tabs[$family->value] = Tab::make($family->getLabel())
                ->icon($family->getIcon())
                ->badge($counts[$family->value] ?: null)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->family($family));
        }

        return $tabs;
    }

    /** @return array<string, int> */
    private function familyCounts(): array
    {
        if ($this->familyCounts !== null) {
            return $this->familyCounts;
        }

        $counts = array_fill_keys(
            array_map(fn (AuditActionFamily $family): string => $family->value, AuditActionFamily::cases()),
            0,
        );

        foreach (AuditLog::query()->toBase()->selectRaw('action, count(*) as aggregate')->groupBy('action')->get() as $row) {
            $counts[AuditActionFamily::fromAction((string) $row->action)->value] += (int) $row->aggregate;
        }

        return $this->familyCounts = $counts;
    }
}
