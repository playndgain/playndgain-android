<?php

namespace App\Filament\Tables\Columns;

use App\Models\App;
use Closure;
use Filament\Tables\Columns\Column;
use Illuminate\Database\Eloquent\Builder;

class AppColumn extends Column
{
    protected string $view = 'filament.tables.columns.app-identity';

    /**
     * @param  bool | array<string> | string | Closure  $condition
     */
    public function searchable(
        bool|array|string|Closure $condition = true,
        ?Closure $query = null,
        bool $isIndividual = false,
        bool $isGlobal = true,
    ): static {
        if ($condition === true && $query === null) {
            $query = function (Builder $query, string $search): void {
                $path = $this->relatedAppPath($query);

                if ($path === null) {
                    $query->where(function (Builder $query) use ($search): void {
                        $query->where('name', 'like', "%{$search}%")
                            ->orWhere('app_id', 'like', "%{$search}%");
                    });

                    return;
                }

                $query->whereHas($path, function (Builder $query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('app_id', 'like', "%{$search}%");
                });
            };
        }

        return parent::searchable($condition, $query, $isIndividual, $isGlobal);
    }

    /**
     * @param  bool | array<string> | Closure  $condition
     */
    public function sortable(bool|array|Closure $condition = true, ?Closure $query = null): static
    {
        if ($condition === true && $query === null) {
            $query = function (Builder $query, string $direction): void {
                $path = $this->relatedAppPath($query);

                if ($path === null) {
                    $query->orderBy('name', $direction);

                    return;
                }

                $query->orderBy(
                    App::query()->select('name')->whereColumn(
                        'apps.id',
                        $query->getModel()->qualifyColumn('app_id'),
                    ),
                    $direction,
                );
            };
        }

        return parent::sortable($condition, $query);
    }

    /** `app` on a conversion or click. Null when the row is an App. */
    private function relatedAppPath(Builder $query): ?string
    {
        if ($query->getModel() instanceof App) {
            return null;
        }

        $name = $this->getName();

        return str_contains($name, '.') ? explode('.', $name)[0] : 'app';
    }
}
