@extends('wall.layout')

@php
    /** @var \App\Services\Wall\WallContext $context */
    /** @var list<array<string, mixed>> $offers */
    /** @var list<string> $categories */
    /** @var string|null $sort */
    $params = $context->params();
    $currency = $context->app->currency_name;

    // The sort form has to carry the wall's own parameters, or submitting it
    // drops appid and sig and lands on a 404. Parsed from the same string every
    // link on the page echoes rather than rebuilt from the request.
    parse_str($params, $fields);

    // The rail is admin-pinned inventory, and the test offer is neither pinned
    // nor inventory: it is `is_featured` so it sorts first, and it is already
    // unshifted above the grid. In the rail it would be the same card twice.
    $featured = array_values(array_filter(
        $offers,
        fn (array $offer): bool => $offer['is_featured'] && ! $offer['is_test'],
    ));

    // One index feeds both the filter and every card: a card reads `list[i]`
    // rather than repeating its own name and categories into its own scope.
    $index = array_map(fn (array $offer): array => [
        'n' => mb_strtolower((string) $offer['name']),
        'i' => mb_strtolower((string) ($offer['instructions'] ?? '')),
        'c' => $offer['categories'],
        // Empty is "any device" and matches every device filter, the same
        // rule OfferFeed::listAllows uses for targeting.
        'd' => array_values(array_intersect(
            array_map(
                fn (string $device): string => mb_strtolower($device),
                array_values(array_filter((array) ($offer['devices'] ?? []), 'is_string')),
            ),
            ['android', 'ios', 'desktop'],
        )),
    ], $offers);

    // Known category names only. Free-form tags from nine catalogues do not
    // get a guessed glyph — an unknown value here would be a box pointing at
    // nothing.
    $chipIcon = static function (string $category): ?string {
        $key = mb_strtolower($category);

        return match (true) {
            str_contains($key, 'game') => 'ic-game',
            str_contains($key, 'survey') => 'ic-survey',
            str_contains($key, 'app') => 'ic-bag',
            str_contains($key, 'web') || str_contains($key, 'desktop') => 'ic-desktop',
            str_contains($key, 'video') => 'ic-video',
            default => null,
        };
    };
@endphp

@section('content')
<a class="wall-skip" href="#offers">{{ __('wall.skip') }}</a>

{{-- Every piece of behaviour lives in resources/js/wall.js as `Alpine.data`.
     Nothing here but state names: the markup stays readable, and the rendered
     HTML carries no Alpine magic — which the wall's own test asserts, because a
     `$` in an attribute is indistinguishable from a currency leak. --}}
<div x-data="wall(@js($index))">

    <header class="wall-header">
        <div class="wall-shell">
            @include('wall.partials.topbar', ['active' => 'offers'])

            <h1 class="wall-sr">{{ __('wall.title') }}</h1>

            @if ($offers !== [] && $categories !== [])
                <div class="wall-chips" role="group" aria-label="{{ __('wall.title') }}">
                    <button type="button" class="wall-chip" :class="cat === 'all' && 'is-on'" :aria-pressed="cat === 'all'" @click="cat = 'all'">
                        <svg class="wall-chip__icon" aria-hidden="true"><use href="#ic-grid"/></svg>
                        {{ __('wall.all') }}
                    </button>
                    @foreach ($categories as $category)
                        <button type="button" class="wall-chip" :class="cat === @js($category) && 'is-on'" :aria-pressed="cat === @js($category)" @click="cat = @js($category)">
                            @if ($icon = $chipIcon($category))
                                <svg class="wall-chip__icon" aria-hidden="true"><use href="#{{ $icon }}"/></svg>
                            @endif
                            {{ $category }}
                        </button>
                    @endforeach
                </div>
            @endif
        </div>
    </header>

    <main id="offers" class="wall-main">
        <div class="wall-shell">
            @if ($offers === [])
                {{-- Names the actual cause rather than shrugging — and there
                     are two causes. Naming the country is only honest when the
                     country is the thing we know. --}}
                <div class="wall-empty">
                    <h2>{{ __($unplaced ? 'wall.unplaced.title' : 'wall.empty.title') }}</h2>
                    <p>{{ __($unplaced ? 'wall.unplaced.body' : 'wall.empty.body') }}</p>
                </div>
            @else
                {{-- The pinned offers as a scrolling strip. Not a carousel: no
                     autoplay, no timer. The browser already operates the track
                     with a wheel, a swipe, a trackpad and the arrow keys; the
                     next control is an enhancement that JS hides when it
                     cannot run.

                     Absent below two featured offers, because a rail of one is
                     a card with a scrollbar. Hidden while the search or a chip
                     is on: a strip of "featured" cards sitting above a filtered
                     grid and ignoring the query reads as a bug. No `x-cloak` on
                     that — with JS off there is no filter, so the rail is
                     simply always there. --}}
                @if (count($featured) > 1)
                    <section class="wall-rail" aria-labelledby="wall-rail-h" x-show="q === '' && cat === 'all' && device === 'all'">
                        <h2 class="wall-rail__h" id="wall-rail-h">{{ __('wall.featured_offers') }}</h2>

                        <div class="wall-rail__frame">
                            <ul class="wall-rail__track">
                                @foreach ($featured as $offer)
                                    <li>@include('wall.partials.card', ['rail' => true])</li>
                                @endforeach
                            </ul>

                            <button type="button" class="wall-rail__next" x-cloak @click="railNext" aria-label="{{ __('wall.rail_next') }}">
                                <span aria-hidden="true"></span>
                            </button>
                        </div>
                    </section>
                @endif

                <div class="wall-catalogue">
                    <div class="wall-catalogue__lede">
                        <h2 class="wall-catalogue__h">{{ __('wall.all_offers') }}</h2>

                        {{-- Only while a filter is on. Unfiltered it would say
                             "All Offers · 9 offers", and a count the user did
                             not ask for is one more thing to read. --}}
                        <p class="wall-header__count num" x-cloak x-show="hits !== list.length">
                            <span x-text="hits">{{ count($offers) }}</span> / {{ count($offers) }}
                        </p>
                    </div>

                    <div class="wall-tools">
                        <label class="wall-search">
                            <span class="wall-sr">{{ __('wall.search') }}</span>

                            <svg class="wall-search__icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                                <circle cx="7" cy="7" r="4.5"/><path d="M10.5 10.5 14 14" stroke-linecap="round"/>
                            </svg>

                            <input type="search" x-model="q" placeholder="{{ __('wall.search_placeholder') }}" autocomplete="off" enterkeyhint="search" spellcheck="false">

                            <button type="button" class="wall-clear" x-cloak x-show="q !== ''"
                                    @click="q = ''" aria-label="{{ __('wall.clear') }}">
                                <svg viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                                    <path d="m1.5 1.5 11 11M12.5 1.5l-11 11"/>
                                </svg>
                            </button>
                        </label>

                        {{-- Client-side, like the chips: the list is already on
                             the page. Hidden until Alpine boots, so a no-JS
                             reader is not left with a control that does nothing. --}}
                        <div class="wall-devices" role="group" aria-label="{{ __('wall.filter.device') }}" x-cloak>
                            <button type="button" class="wall-devices__btn" :class="device === 'all' && 'is-on'" :aria-pressed="device === 'all'" @click="device = 'all'" aria-label="{{ __('wall.filter.all_devices') }}">
                                {{ __('wall.all') }}
                            </button>
                            @foreach (['android', 'ios', 'desktop'] as $kind)
                                <button type="button" class="wall-devices__btn" :class="device === '{{ $kind }}' && 'is-on'" :aria-pressed="device === '{{ $kind }}'" @click="device = '{{ $kind }}'" aria-label="{{ __("wall.device.{$kind}") }}">
                                    <svg class="wall-devices__icon" aria-hidden="true"><use href="#ic-{{ $kind }}"/></svg>
                                </button>
                            @endforeach
                        </div>

                        {{-- Server-side, as a form submit — not a client-side reorder.
                             The filter above may hide DOM nodes, and that is fine; a
                             *sort* done with CSS `order` or a shuffled Alpine index
                             makes visual order diverge from DOM and focus order, which
                             is a keyboard bug design.md §7 does not permit.

                             With JS off it is a select and a button, and it works. The
                             button is hidden once Alpine can submit on `change` — by
                             `x-show` and not by <noscript>, so a bundle that fails to
                             load leaves the working control on the page rather than a
                             select that does nothing. --}}
                        <form class="wall-sort" method="get" action="{{ route('wall') }}">
                            @foreach ($fields as $name => $value)
                                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                            @endforeach

                            <label class="wall-sr" for="wall-sort">{{ __('wall.sort.label') }}</label>

                            <select class="wall-sort__select" id="wall-sort" name="sort" @change="sortNow">
                                <option value="">{{ __('wall.sort.featured') }}</option>
                                <option value="reward" @selected($sort === 'reward')>{{ __('wall.sort.reward') }}</option>
                                <option value="trending" @selected($sort === 'trending')>{{ __('wall.sort.trending') }}</option>
                            </select>

                            <button type="submit" class="wall-sort__go" x-show="false">{{ __('wall.sort.apply') }}</button>
                        </form>
                    </div>
                </div>

                <ul class="wall-grid">
                    @foreach ($offers as $offer)
                        {{-- Two conditions, one array lookup each: is this card in
                             the current filter, and is it inside the reveal window.
                             With JS off neither applies and every card is here. --}}
                        <li x-show="rank[{{ $loop->index }}] > -1 && rank[{{ $loop->index }}] < shown">
                            @include('wall.partials.card')
                        </li>
                    @endforeach
                </ul>

                {{-- The window's edge, and the user's own decision to widen it.
                     A button rather than a scroll trigger: an auto-loading list
                     never ends, which means the footer never arrives and a user
                     looking for it scrolls forever.

                     `x-cloak` hides it with JS off, where there is nothing to
                     reveal — every card is already in the DOM. --}}
                <div class="wall-more" x-cloak x-show="shown < hits">
                    <button type="button" class="wall-button" @click="more">
                        {{ __('wall.more') }}
                        <span class="wall-more__count num">
                            <span x-text="hits - shown"></span>
                        </span>
                    </button>
                </div>

                {{-- The filtered-empty state, which is not the server-empty one:
                     nothing is missing from the wall, the query is just too
                     narrow, so it offers the way back. --}}
                <div class="wall-empty" role="status" x-cloak x-show="hits === 0">
                    <h2>{{ __('wall.no_results.title') }}</h2>
                    <p>{{ __('wall.no_results.body') }}</p>
                    <button type="button" class="wall-button" @click="q = ''; cat = 'all'; device = 'all'">{{ __('wall.no_results.reset') }}</button>
                </div>
            @endif
        </div>
    </main>
</div>

@include('wall.partials.nav', ['active' => 'offers'])
@include('wall.partials.menu')

{{-- The detail shell. Native <dialog> for the focus trap, the Esc key and the
     backdrop — all three hand-rolled badly is the usual way a modal fails a
     keyboard user. Empty until a card is clicked; the fragment it receives
     carries no Alpine directives, because Alpine does not walk a subtree that
     arrives after boot. --}}
<dialog class="offer-dialog" id="offer-dialog" aria-label="{{ __('wall.title') }}">
    {{-- Chrome sits outside the scrollport so the scrollbar cannot run
         through the close button, which is what an absolutely-positioned
         X on a scrolling body does. --}}
    <div class="offer-dialog__chrome">
        <button type="button" class="offer-dialog__close" data-close aria-label="{{ __('wall.offer.close') }}">
            <svg viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                <path d="m1.5 1.5 11 11M12.5 1.5l-11 11"/>
            </svg>
        </button>
    </div>

    <div class="offer-dialog__body" id="offer-dialog-body"></div>

    <template id="offer-dialog-skeleton">
        <div class="offer-skeleton">
            <p class="wall-sr">{{ __('wall.offer.loading') }}</p>
            <div class="offer-skeleton__head">
                <span class="wall-skeleton__tile offer-skeleton__tile"></span>
                <span class="offer-skeleton__lede">
                    <span class="wall-skeleton__line"></span>
                    <span class="wall-skeleton__line wall-skeleton__line--short"></span>
                    <span class="wall-skeleton__reward"></span>
                </span>
            </div>
            <span class="wall-skeleton__line"></span>
            <span class="wall-skeleton__line"></span>
            <span class="wall-skeleton__line wall-skeleton__line--short"></span>
            <div class="offer-skeleton__cta">
                <span class="wall-skeleton__reward offer-skeleton__go"></span>
            </div>
        </div>
    </template>
</dialog>
@endsection
