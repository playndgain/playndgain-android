<?php

namespace App\Services\Wall;

use App\Models\Offer;
use App\Support\Plain;

/**
 * The only shapes the native SDKs receive. Every field is listed by hand.
 *
 * Never spread a feed row into a response: the cached payload carries the
 * upstream USD `payout`, and AGENTS.md forbids a publisher (and so their app)
 * from ever seeing it, or the network an offer came from.
 */
final class SdkPayload
{
    /**
     * @param  array<string, mixed>  $row  One row of OfferFeed::for()
     * @return array<string, mixed>
     */
    public static function offer(array $row, WallContext $context): array
    {
        $query = $context->params();
        $goals = (int) ($row['goal_count'] ?? 0);
        $people = (int) ($row['people'] ?? 0);

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'instructions' => (string) $row['instructions'],
            'reward' => (float) $row['reward'],
            'reward_label' => (string) $row['reward_label'],
            'multi_reward' => (bool) $row['is_multi_reward'],
            'goal_count' => $goals,
            // Finished strings: `wall.steps` and `wall.people` are plural
            // templates, and the SDKs ship no translation engine.
            'steps_label' => $goals > 1 ? trans_choice('wall.steps', $goals, ['count' => $goals]) : null,
            'people' => $people,
            'people_label' => $people > 0 ? '+'.number_format($people) : null,
            'people_a11y' => $people > 0 ? number_format($people).' '.trans_choice('wall.people', $people) : null,
            'featured' => (bool) $row['is_featured'],
            'test' => (bool) $row['is_test'],
            'categories' => array_values((array) $row['categories']),
            'devices' => array_values((array) $row['devices']),
            'icon_url' => ($row['has_icon'] ?? false) ? url('/img/offer/'.$row['id']) : null,
            'detail_url' => route('sdk.offer', $row['id']).'?'.$query,
            'click_url' => route('wall.click', $row['id']).'?'.$query,
        ];
    }

    /**
     * The detail screen. Read straight from the row like `OfferDetailController`
     * does, and converted per step the same way, so the native sheet and the
     * HTML sheet quote the same figures.
     *
     * @param  list<array{name: string, reward: string}>  $steps
     * @return array<string, mixed>
     */
    public static function detail(Offer $offer, WallContext $context, string $rewardLabel, array $steps, int $people): array
    {
        $currency = (string) $context->app->currency_name;
        $countries = OfferCountries::of($offer);
        $goals = count($steps);

        return [
            'id' => (int) $offer->id,
            'name' => (string) $offer->name,
            'reward_label' => $rewardLabel,
            'multi_reward' => (bool) $offer->is_multi_reward,
            'steps' => $steps,
            'steps_label' => $goals > 1 ? trans_choice('wall.steps', $goals, ['count' => $goals]) : null,
            'steps_note' => $goals > 1 ? __('wall.offer.steps_note', ['total' => $rewardLabel.' '.$currency]) : null,
            'notes' => Plain::lines($offer->instructions),
            'lead' => Plain::text($offer->description),
            'devices' => array_values(array_filter((array) ($offer->devices ?? []), 'is_string')),
            'countries' => array_map(
                fn (string $code): array => ['code' => strtoupper($code), 'name' => OfferCountries::name($code)],
                $countries,
            ),
            'os_min_version' => $offer->os_min_version,
            'people' => $people,
            'people_label' => $people > 0 ? '+'.number_format($people) : null,
            'icon_url' => filled($offer->icon_url) ? url('/img/offer/'.$offer->id) : null,
            'important' => array_values((array) trans('wall.offer.note')),
            'click_url' => route('wall.click', $offer->id).'?'.$context->params(),
        ];
    }

    /** @return array<string, mixed> */
    public static function app(WallContext $context): array
    {
        $vars = $context->cssVars();

        return [
            'name' => (string) $context->app->name,
            'currency_name' => (string) $context->app->currency_name,
            'locale' => $context->locale,
            'rtl' => $context->dir() === 'rtl',
            'theme' => [
                'primary' => $vars['--wall-primary'],
                'on_primary' => $vars['--wall-on-primary'],
                'background' => $vars['--wall-bg'],
                'card' => $vars['--wall-card'],
                'text' => $vars['--wall-text'],
                'radius' => self::px($vars['--wall-radius']),
                'dark' => $context->isDark(),
            ],
            'balance' => $context->balance,
            'test_mode' => $context->testMode,
        ];
    }

    /**
     * The wall's own copy, so the SDKs ship no translations. Partner and menu
     * strings are left out: the SDK has neither surface.
     *
     * Every value is a finished sentence. `earn` is filled with the app's
     * currency here; any other value still carrying a `:placeholder` or a
     * plural marker is removed and sent per offer instead (`steps_label`,
     * `steps_note`, `people_label`).
     *
     * @return array<string, mixed>
     */
    public static function strings(WallContext $context): array
    {
        $all = (array) trans('wall');
        unset($all['partner'], $all['menu']);

        $all['earn'] = __('wall.earn', ['currency' => $context->app->currency_name]);

        return self::withoutTemplates($all);
    }

    /**
     * @param  array<string, mixed>  $strings
     * @return array<string, mixed>
     */
    private static function withoutTemplates(array $strings): array
    {
        $clean = [];

        foreach ($strings as $key => $value) {
            if (is_array($value)) {
                $clean[$key] = self::withoutTemplates($value);

                continue;
            }

            if (is_string($value) && self::isTemplate($value)) {
                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    public static function isTemplate(string $value): bool
    {
        return preg_match('/:[a-z_]+/i', $value) === 1
            || str_contains($value, '|[')
            || str_starts_with($value, '{');
    }

    /** `14px`, `1rem` or `1.5em` as whole pixels, at 16px per (r)em. */
    public static function px(string $radius): int
    {
        if (preg_match('/^(\d+(?:\.\d+)?)(px|rem|em)$/', $radius, $m) !== 1) {
            return 14;
        }

        return (int) round($m[2] === 'px' ? (float) $m[1] : (float) $m[1] * 16);
    }
}
