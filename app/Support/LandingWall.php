<?php

namespace App\Support;

use App\Services\Wall\WallContext;

/**
 * Sample inventory for the landing mock.
 *
 * The phone on `/` is the wall's own chrome, not a restyle of it. These four
 * rows are the same array the card partial already understands, with store
 * artwork so the tiles are not gradient squares (docs/09 §5).
 */
final class LandingWall
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function offers(): array
    {
        return [
            [
                'name' => 'Coin Master',
                'instructions' => 'Reach village 10',
                'reward_label' => '640',
                'is_featured' => true,
                'is_test' => false,
                'is_multi_reward' => false,
                'goal_count' => 1,
                'icon_src' => '/img/landing/offers/coin-master.webp',
                'devices' => ['ios'],
                'people' => 1284,
                'live' => true,
            ],
            [
                'name' => 'MONOPOLY GO!',
                'instructions' => 'Reach board 20',
                'reward_label' => '890',
                'is_featured' => true,
                'is_test' => false,
                'is_multi_reward' => false,
                'goal_count' => 1,
                'icon_src' => '/img/landing/offers/monopoly-go.webp',
                'devices' => ['android', 'ios'],
                'people' => 2106,
            ],
            [
                'name' => 'Temu',
                'instructions' => 'Make a first purchase',
                'reward_label' => '318',
                'is_featured' => false,
                'is_test' => false,
                'is_multi_reward' => false,
                'goal_count' => 1,
                'icon_src' => '/img/landing/offers/temu.webp',
                'devices' => ['android'],
                'people' => 742,
            ],
            [
                'name' => 'TikTok',
                'instructions' => 'Create an account',
                'reward_label' => '210',
                'is_featured' => false,
                'is_test' => false,
                'is_multi_reward' => false,
                'goal_count' => 1,
                'icon_src' => '/img/landing/offers/tiktok.webp',
                'devices' => ['android', 'ios'],
                'people' => 968,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function themeStyles(): array
    {
        $themes = [
            'obsidian' => WallContext::DEFAULT_THEME,
            'daylight' => [
                'primary' => '#F5A524',
                'background' => '#FFFFFF',
                'card' => '#F4F5F7',
                'text' => '#14171B',
                'radius' => '12px',
                'dark' => false,
            ],
            'forest' => [
                'primary' => '#4FC98A',
                'background' => '#08201A',
                'card' => '#0D2B22',
                'text' => '#E9ECF1',
                'radius' => '12px',
                'dark' => true,
            ],
            'violet' => [
                'primary' => '#A78BFA',
                'background' => '#1B1233',
                'card' => '#241847',
                'text' => '#E9ECF1',
                'radius' => '12px',
                'dark' => true,
            ],
        ];

        return array_map(
            fn (array $theme): string => collect(WallContext::vars($theme))
                ->map(fn (string $value, string $key): string => "{$key}: {$value}")
                ->implode('; '),
            $themes,
        );
    }
}
