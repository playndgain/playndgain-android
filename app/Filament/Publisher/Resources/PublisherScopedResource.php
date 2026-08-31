<?php

namespace App\Filament\Publisher\Resources;

use App\Filament\Publisher\Support\CurrentPublisher;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;

/**
 * Base for every resource in the publisher panel — docs/08 §1.
 *
 * The models already carry `PublisherScope`, and the policies already check
 * ownership. This is the third mechanism, and it exists on purpose: the cost of
 * a leak here is one publisher seeing another's revenue, and a global scope is
 * one `withoutGlobalScopes()` away from being absent.
 *
 * A publisher row that cannot be resolved scopes to id 0, so a broken session
 * sees nothing rather than everything.
 */
abstract class PublisherScopedResource extends Resource
{
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where(static::publisherColumn(), CurrentPublisher::id());
    }

    /** Overridden by resources whose table names the column differently. */
    protected static function publisherColumn(): string
    {
        return 'publisher_id';
    }
}
