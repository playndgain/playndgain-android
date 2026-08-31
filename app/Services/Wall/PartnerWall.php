<?php

namespace App\Services\Wall;

use App\Models\Network;

/**
 * A partner's own hosted wall, addressed by macro template — docs/03 §2.
 *
 * The template is admin input; the values substituted into it are end-user
 * input. That asymmetry is the whole of this class: the shape of the URL is
 * trusted, everything poured into it is not.
 */
final class PartnerWall
{
    /** The public contract. Named in the admin helper text and in the spec. */
    public const MACROS = ['sub_id', 'user_id', 'app_id', 'country', 'device', 'lang'];

    public static function isEmbeddable(Network $network): bool
    {
        return $network->isActive()
            && is_string($network->wall_url)
            // Not `str_contains`: `javascript:alert('https://')` contains it.
            && str_starts_with(strtolower($network->wall_url), 'https://');
    }

    public static function url(Network $network, WallContext $context, string $subId): string
    {
        $values = [
            'sub_id' => $subId,
            'user_id' => $context->endUserId,
            'app_id' => (string) $context->app->app_id,
            'country' => (string) $context->country,
            'device' => $context->device->value,
            'lang' => $context->locale,
        ];

        $search = [];
        $replace = [];

        // Built from the whitelist rather than from the template, so a macro
        // nobody defined survives the substitution untouched. A silently
        // emptied `{clickid}` is an integration that fails at payout time
        // instead of at setup time.
        foreach (self::MACROS as $macro) {
            $search[] = '{'.$macro.'}';
            $replace[] = rawurlencode($values[$macro]);
        }

        return str_replace($search, $replace, (string) $network->wall_url);
    }
}
