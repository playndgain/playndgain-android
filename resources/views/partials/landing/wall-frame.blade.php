@php
    /** @var list<array<string, mixed>> $offers */
    /** @var bool $compact */
    /** @var bool $live */
    /** @var bool $bindTheme */
    /** @var array<string, string> $styles */

    $compact = $compact ?? false;
    $live = $live ?? false;
    $bindTheme = $bindTheme ?? false;
    $styles = $styles ?? \App\Support\LandingWall::themeStyles();
    $offers = $offers ?? \App\Support\LandingWall::offers();
    $currency = 'Coins';
@endphp

{{-- The wall's own chrome, framed as a phone. Same cards, same tokens, same
     classes as /wall and the publisher preview — a restyle here would be a
     second wall. --}}
<div class="landing-wall @if ($compact) landing-wall--compact @endif">
  <div
    class="wall wall-preview__stage"
    style="{{ $styles['obsidian'] }}"
    @if ($bindTheme)
      :style="styles[theme]"
      :class="theme === 'daylight' ? 'wall--light' : ''"
    @endif
  >
    <header class="wall-header">
      <div class="wall-shell">
        <div class="wall-topbar">
          <span class="wall-topbar__brand">
            <x-brand-mark :size="26" />
          </span>

          <span class="wall-topbar__end">
            <span class="wall-topbar__cluster">
              <span class="wall-balance" title="Coins">
                <svg class="wall-balance__icon" aria-hidden="true"><use href="#ic-coin"/></svg>
                <span @if ($live) id="coinBal" @endif class="num" dir="ltr">2,430</span>
                <span class="wall-sr">Coins</span>
              </span>
            </span>
          </span>
        </div>

        @unless ($compact)
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
              <svg class="wall-chip__icon" aria-hidden="true"><use href="#ic-bag"/></svg>
              Apps
            </span>
          </div>
        @endunless
      </div>
    </header>

    <div class="wall-preview__scroll">
      <main class="wall-main">
        <div class="wall-shell">
          @unless ($compact)
            <div class="wall-catalogue">
              <div class="wall-catalogue__lede">
                <h2 class="wall-catalogue__h">{{ __('wall.all_offers') }}</h2>
              </div>
            </div>
          @endunless

          <ul class="wall-grid">
            @foreach ($offers as $offer)
              <li @if ($live && ($offer['live'] ?? false)) id="liveRow" class="relative" @endif>
                @include('wall.partials.card', [
                  'preview' => true,
                  'compact' => $compact,
                  'currency' => $currency,
                  'params' => '',
                ])

                @if ($live && ($offer['live'] ?? false))
                  <span id="floatWin" class="num landing-wall__float">+640</span>
                @endif
              </li>
            @endforeach
          </ul>
        </div>
      </main>
    </div>

    @unless ($compact)
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
    @endunless
  </div>
</div>
