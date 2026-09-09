<?php

namespace App\Filament\Admin\Analytics;

use App\Enums\ConversionStatus;
use App\Enums\SourceDimension;
use App\Filament\Admin\Resources\Apps\AppResource;
use App\Filament\Admin\Resources\Conversions\ConversionResource;
use App\Filament\Admin\Resources\OfferClicks\OfferClickResource;
use App\Filament\Admin\Resources\Offers\OfferResource;
use App\Filament\Admin\Resources\Publishers\PublisherResource;

final class SourceLinks
{
    public static function url(SourceDimension $dimension, string $key, ?string $label = null): ?string
    {
        if ($key === 'unknown') {
            return null;
        }

        return match ($dimension) {
            SourceDimension::Publisher => PublisherResource::getUrl('view', ['record' => $key]),
            SourceDimension::App => AppResource::getUrl('view', ['record' => $key]),
            SourceDimension::Offer => OfferResource::getUrl('index', ['tableSearch' => $label ?? $key]),
            SourceDimension::Country => ConversionResource::getUrl('index', [
                'tableFilters' => ['country' => ['country' => $key]],
            ]),
            SourceDimension::Ip => OfferClickResource::getUrl('index', ['tableSearch' => $key]),
            SourceDimension::EndUser => self::endUser($key),
        };
    }

    public static function reversedConversions(): string
    {
        return ConversionResource::getUrl('index', [
            'tableFilters' => ['status' => ['value' => ConversionStatus::Reversed->value]],
        ]);
    }

    private static function endUser(string $key): string
    {
        [$publisherId, $endUserId] = array_pad(explode(':', $key, 2), 2, '');

        return OfferClickResource::getUrl('index', [
            'tableFilters' => ['publisher_id' => ['value' => $publisherId]],
            'tableSearch' => $endUserId,
        ]);
    }
}
