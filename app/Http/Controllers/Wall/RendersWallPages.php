<?php

namespace App\Http\Controllers\Wall;

use App\Services\Wall\WallContext;
use Illuminate\Http\Response;

/**
 * Every wall page carries `Cache-Control: private, no-store` (docs/03 §6). The
 * HTML is per end user — a proxy or a browser cache holding it would show one
 * user another user's offer list and reward balance.
 */
trait RendersWallPages
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function page(string $view, array $data = [], int $status = Response::HTTP_OK): Response
    {
        $context = $data['context'] ?? null;

        if ($context instanceof WallContext) {
            app()->setLocale($context->locale);
        }

        return response()
            ->view("wall.{$view}", $data, $status)
            ->header('Cache-Control', 'private, no-store');
    }

    /**
     * The one screen for every dead end: unavailable app, banned user, declined
     * consent, blocked click. Same markup, different sentence — a user who
     * cannot proceed needs a reason, not four designs.
     */
    protected function notice(string $key, ?WallContext $context = null, int $status = Response::HTTP_OK): Response
    {
        return $this->page('notice', [
            'context' => $context,
            'title' => __("wall.notice.{$key}.title"),
            'body' => __("wall.notice.{$key}.body"),
            // Consent has to be withdrawable and re-grantable, or the first
            // answer is permanent and the gate is a trap.
            'declined' => $key === 'declined',
        ], $status);
    }
}
