<?php

namespace App\Filament\Publisher\Support;

use App\Filament\Admin\Support\TestPostbackAction;
use App\Models\App;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The admin's test-postback button, rate-limited for the publisher panel.
 *
 * Same request, same result shown — a support conversation where the two sides
 * see different output is a support conversation that goes nowhere.
 */
final class PublisherTestPostbackAction
{
    /** Per app, per minute. Generous for testing, useless for hammering someone. */
    public const PER_MINUTE = 10;

    public static function make(): Action
    {
        return TestPostbackAction::make('publisher_test_postback')
            ->before(function (App $record, Action $action): void {
                $key = "test-postback:{$record->id}";

                if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
                    Notification::make()
                        ->title('Slow down')
                        ->body('Test postbacks are limited to '.self::PER_MINUTE.' a minute per app. Try again in '
                            .RateLimiter::availableIn($key).'s.')
                        ->warning()
                        ->send();

                    $action->cancel();
                }

                RateLimiter::hit($key);
            });
    }
}
