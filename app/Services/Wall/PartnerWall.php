<?php

namespace App\Services\Wall;

use App\Models\App;
use App\Models\Network;
use App\Support\AppNetworks;
use Illuminate\Database\Eloquent\Collection;

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

    /**
     * Partner walls this placement can actually open. Request-memoised so the
     * tab strip, the app-nav and the menu agree without a second query.
     *
     * @return Collection<int, Network>
     */
    public static function catalogue(App $app): Collection
    {
        $key = 'wall.partnerCatalogue.'.$app->id;

        if (request()->attributes->has($key)) {
            /** @var Collection<int, Network> */
            return request()->attributes->get($key);
        }

        $blocked = AppNetworks::blockedIds($app);

        $catalogue = Network::query()->embeddable()->get()
            ->filter(self::isEmbeddable(...))
            ->reject(fn (Network $partner): bool => in_array($partner->id, $blocked, true))
            ->values();

        request()->attributes->set($key, $catalogue);

        return $catalogue;
    }

    public static function available(App $app): bool
    {
        return self::catalogue($app)->isNotEmpty();
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
