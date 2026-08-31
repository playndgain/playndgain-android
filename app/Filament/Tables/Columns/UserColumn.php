<?php

namespace App\Filament\Tables\Columns;

use App\Models\User;
use Closure;
use Filament\Tables\Columns\Column;
use Illuminate\Database\Eloquent\Builder;

class UserColumn extends Column
{
    protected string $view = 'filament.tables.columns.user-column';

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
                $path = $this->relatedUserPath($query);

                if ($path === null) {
                    $query->where(function (Builder $query) use ($search): void {
                        $query->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });

                    return;
                }

                $query->whereHas($path, function (Builder $query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
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
                $path = $this->relatedUserPath($query);

                if ($path === null) {
                    $query->orderBy('name', $direction);

                    return;
                }

                if ($path === 'publisher.user') {
                    $query->orderBy(
                        User::query()
                            ->select('users.name')
                            ->join('publishers', 'publishers.user_id', '=', 'users.id')
                            ->whereColumn('publishers.id', $query->getModel()->qualifyColumn('publisher_id')),
                        $direction,
                    );

                    return;
                }

                $query->orderBy(
                    User::query()->select('name')->whereColumn(
                        'users.id',
                        $query->getModel()->qualifyColumn('user_id'),
                    ),
                    $direction,
                );
            };
        }

        return parent::sortable($condition, $query);
    }

    /** `user` on Publisher, `publisher.user` on App. Null when the row is a User. */
    private function relatedUserPath(Builder $query): ?string
    {
        if ($query->getModel() instanceof User) {
            return null;
        }

        $name = $this->getName();

        return str_contains($name, '.') ? $name : 'user';
    }
}
