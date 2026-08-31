{{--
    The applicant's / publisher's own wall, rendered from whatever is currently
    in the form — docs/08 §2 (Integration), §5 (Appearance) and §15 (the
    right-hand panel).

    Deliberately a local render and not an iframe of `/wall`:

      - The wall reads `apps.theme` out of the database, so an iframe can only
        ever show the *saved* palette. "Change the theme, see it" is the whole
        requirement, and a preview that lags a save is not one.
      - `WallGate::isServable()` refuses a pending app, and every app is pending
        for its first day. An iframe would show the unavailable notice to
        exactly the publishers this panel is for.
      - During registration there is no app yet, so there is no URL to frame.

    It shares the wall's own custom properties (WallContext::vars) and the
    wall's own classes (wall.css → wall-ui.css), so the colours, cards, and
    chrome are the wall's — not an approximation of them.

    @param array<string, string> $vars      From WallContext::vars()
    @param string                $appName
    @param string                $currencyName
    @param float                 $rate      Units per $1
    @param float                 $revShare  Percent
    @param bool                  $dark
--}}
@php
    $dark ??= true;
    $currency = $currencyName;

    // Three shapes of inventory, so the preview shows what a payout range does
    // to the reward column rather than one number in isolation. Payouts are
    // the same figures the registration test prices: $4.20 / $0.50 / $0.18.
    $samples = [
        [
            'name' => 'Coin Master',
            'instructions' => 'Reach village 15',
            'payout' => 4.20,
            'is_featured' => true,
            'is_test' => false,
            'is_multi_reward' => false,
            'goal_count' => 4,
            'has_icon' => false,
            'devices' => ['android'],
            'people' => 1284,
        ],
        [
            'name' => 'Pollfish',
            'instructions' => 'Answer a 5 minute survey',
            'payout' => 0.50,
            'is_featured' => true,
            'is_test' => false,
            'is_multi_reward' => false,
            'goal_count' => 1,
            'has_icon' => false,
            'devices' => [],
            'people' => 412,
        ],
        [
            'name' => 'Fetch Rewards',
            'instructions' => 'Install and open the app',
            'payout' => 0.18,
            'is_featured' => false,
            'is_test' => false,
            'is_multi_reward' => false,
            'goal_count' => 1,
            'has_icon' => false,
            'devices' => ['android', 'ios'],
            'people' => 0,
        ],
    ];

    $reward = static fn (float $payout): string => number_format($payout * ($revShare / 100) * $rate);

    $samples = array_map(static function (array $sample) use ($reward): array {
        $sample['reward_label'] = $reward($sample['payout']);

        return $sample;
    }, $samples);

    $featured = array_values(array_filter(
        $samples,
        static fn (array $offer): bool => $offer['is_featured'] && ! $offer['is_test'],
    ));

    $style = collect($vars)->map(fn (string $value, string $key): string => "{$key}: {$value}")->implode('; ');
@endphp

<div class="wall-preview">
    <div
        style="{{ $style }}"
        class="wall wall-preview__stage @unless ($dark) wall--light @endunless"
    >
        @include('partials.icon-sprite')

        <h1 class="wall-sr">{{ $appName }}</h1>

        <header class="wall-header">
            <div class="wall-shell">
                <div class="wall-topbar">
                    <span class="wall-topbar__brand">
                        <x-brand-mark :size="26" />
                    </span>

                    <span class="wall-topbar__end">
                        <nav class="wall-tabs" aria-hidden="true">
                            <span class="wall-tab is-on">
                                <svg class="wall-tab__icon" aria-hidden="true"><use href="#ic-spark"/></svg>
                                {{ __('wall.tabs.offers') }}
                            </span>
                            <span class="wall-tab">
                                <svg class="wall-tab__icon" aria-hidden="true"><use href="#ic-clock"/></svg>
                                {{ __('wall.tabs.history') }}
                            </span>
                            <span class="wall-tab">
                                <svg class="wall-tab__icon" aria-hidden="true"><use href="#ic-grid"/></svg>
                                {{ __('wall.tabs.partners') }}
                            </span>
                        </nav>

                        <span class="wall-topbar__cluster">
                            <span class="wall-balance" title="{{ $currencyName }}">
                                <svg class="wall-balance__icon" aria-hidden="true"><use href="#ic-coin"/></svg>
                                <span class="num" dir="ltr">1,250</span>
                                <span class="wall-sr">{{ $currencyName }}</span>
                            </span>
                        </span>
                    </span>
                </div>

                <div class="wall-chips" role="group" aria-label="{{ __('wall.title') }}">
                    <span class="wall-chip is-on">
                        <svg class="wall-chip__icon" aria-hidden="true"><use href="#ic-grid"/></svg>
                        {{ __('wall.all') }}
                    </span>
                    <span class="wall-chip">
                        <svg class="wall-chip__icon" aria-hidden="true"><use href="#ic-game"/></svg>
                        Games
                    </span>
                    <span class="wall-chip">
                        <svg class="wall-chip__icon" aria-hidden="true"><use href="#ic-survey"/></svg>
                        Surveys
                    </span>
                    <span class="wall-chip">
                        <svg class="wall-chip__icon" aria-hidden="true"><use href="#ic-bag"/></svg>
                        Apps
                    </span>
                </div>
            </div>
        </header>

        <div class="wall-preview__scroll">
            <main class="wall-main">
                <div class="wall-shell">
                    @if (count($featured) > 1)
                        <section class="wall-rail" aria-labelledby="wall-preview-rail">
                            <h2 class="wall-rail__h" id="wall-preview-rail">{{ __('wall.featured_offers') }}</h2>

                            <div class="wall-rail__frame">
                                <ul class="wall-rail__track">
                                    @foreach ($featured as $offer)
                                        <li>@include('wall.partials.card', ['rail' => true, 'preview' => true])</li>
                                    @endforeach
                                </ul>
                            </div>
                        </section>
                    @endif

                    <div class="wall-catalogue">
                        <div class="wall-catalogue__lede">
                            <h2 class="wall-catalogue__h">{{ __('wall.all_offers') }}</h2>
                        </div>

                        <div class="wall-tools">
                            <label class="wall-search">
                                <span class="wall-sr">{{ __('wall.search') }}</span>
                                <svg class="wall-search__icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                                    <circle cx="7" cy="7" r="4.5"/><path d="M10.5 10.5 14 14" stroke-linecap="round"/>
                                </svg>
                                <input type="search" placeholder="{{ __('wall.search_placeholder') }}" disabled>
                            </label>

                            <div class="wall-devices" role="group" aria-label="{{ __('wall.filter.device') }}">
                                <span class="wall-devices__btn is-on">{{ __('wall.all') }}</span>
                                @foreach (['android', 'ios', 'desktop'] as $kind)
                                    <span class="wall-devices__btn">
                                        <svg class="wall-devices__icon" aria-hidden="true"><use href="#ic-{{ $kind }}"/></svg>
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <ul class="wall-grid">
                        @foreach ($samples as $offer)
                            <li>@include('wall.partials.card', ['rail' => false, 'preview' => true])</li>
                        @endforeach
                    </ul>
                </div>
            </main>

            <footer class="wall-footer">
                <span>{{ __('wall.powered_by') }}</span>
                <x-brand-mark :size="16" />
            </footer>
        </div>

        <nav class="wall-nav" aria-label="{{ __('wall.tabs.label') }}">
            <span class="wall-nav__item is-on">
                <svg class="wall-nav__icon" aria-hidden="true"><use href="#ic-home"/></svg>
                <span>{{ __('wall.tabs.offers') }}</span>
            </span>
            <span class="wall-nav__item">
                <svg class="wall-nav__icon" aria-hidden="true"><use href="#ic-clock"/></svg>
                <span>{{ __('wall.tabs.history') }}</span>
            </span>
            <span class="wall-nav__item">
                <svg class="wall-nav__icon" aria-hidden="true"><use href="#ic-grid"/></svg>
                <span>{{ __('wall.tabs.partners') }}</span>
            </span>
            <span class="wall-nav__item">
                <svg class="wall-nav__icon" aria-hidden="true"><use href="#ic-menu"/></svg>
                <span>{{ __('wall.menu.label') }}</span>
            </span>
        </nav>
    </div>

    <p class="wall-preview__note">
        Sample inventory at your {{ rtrim(rtrim(number_format($revShare, 2), '0'), '.') }}% share and
        {{ number_format($rate) }} {{ $currencyName }} per $1. Real offers are whatever is eligible for your traffic.
    </p>
</div>
