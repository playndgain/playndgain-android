@php
    /** @var \App\Services\Wall\WallContext $context */
    /** @var \App\Models\Offer $offer */
    /** @var string $reward */
    /** @var list<array{name: string, reward: string}> $steps */
    $currency = $context->app->currency_name;
    $params = $context->params();

    $devices = array_values(array_filter((array) ($offer->devices ?? []), 'is_string'));
    $categories = array_values(array_filter((array) ($offer->categories ?? []), 'is_string'));

    // ISO-2 or nothing — see App\Services\Wall\OfferCountries, shared with the
    // SDK API so the HTML sheet and the native sheet agree.
    $countries = \App\Services\Wall\OfferCountries::of($offer);

    // Networks pack several sentences into one field — newline-separated, or
    // `<br>`-separated, or both. Split makes them a list; unsplit they run
    // together into a wall of text nobody reads, which is the state this whole
    // page exists to fix. See App\Support\Plain for the markup half.
    $notes = \App\Support\Plain::lines($offer->instructions);
    $lead = \App\Support\Plain::text($offer->description);

    $countryName = fn (string $code): string => \App\Services\Wall\OfferCountries::name($code);

    // Eight is what fits two rows on a phone. The rest become one chip that
    // says how many, because a hundred flags is a texture, not information.
    $shownCountries = array_slice($countries, 0, 8);
@endphp

{{-- Deliberately free of Alpine directives. The wall injects this same markup
     into a dialog with innerHTML, and Alpine does not walk a subtree that
     arrives after boot — a directive here would silently do nothing on the
     path most users take. Everything interactive lives on the shell. --}}
<article class="offer">

    <header class="offer__head">
        <span class="wall-tile wall-tile--xl">
            <span class="wall-tile__letter" aria-hidden="true">{{ mb_substr((string) $offer->name, 0, 1) }}</span>
            @if (filled($offer->icon_url))
                {{-- 56², the size `.wall-tile--xl` actually paints. The image
                     is absolutely positioned, so these attributes only supply
                     the ratio — but a square that is not the box's square is a
                     lie about the art, and offer icons are square. --}}
                <img class="wall-tile__img" src="/img/offer/{{ $offer->id }}"
                     alt="" width="56" height="56" decoding="async" onerror="this.remove()">
            @endif
        </span>

        <div class="offer__lede">
            <h1 class="offer__name">{{ $offer->name }}</h1>

            @if ($categories !== [])
                <p class="offer__cats">{{ implode(' · ', $categories) }}</p>
            @endif

            {{-- The figure the whole page is about, in the same treatment the
                 card carries, so the number the user tapped is the number they
                 land on. --}}
            <p class="wall-reward offer__reward">
                @if (count($steps) > 1)
                    <span class="wall-reward__upto">{{ __('wall.up_to') }}</span>
                @endif
                <span class="num wall-reward__value">{{ $reward }}</span>
                <span class="wall-reward__currency">{{ $currency }}</span>
            </p>

            {{-- The same two facts the card carries, for the same reason as the
                 pill above: the screen the user landed on confirms the one they
                 tapped. --}}
            <span class="offer__facts">
                @include('wall.partials.facts', ['devices' => $devices, 'people' => (int) ($people ?? 0)])
            </span>
        </div>
    </header>

    @if (filled($lead) && $lead !== ($notes[0] ?? null))
        <p class="offer__lead">{{ $lead }}</p>
    @endif

    <section class="offer__section">
        <h2 class="offer__h">{{ __('wall.offer.steps') }}</h2>

        {{-- An ordered list, because the order is the information: a step you
             cannot reach before the one above it. --}}
        <ol class="offer-steps">
            @foreach ($steps as $i => $step)
                <li class="offer-step">
                    <span class="offer-step__n num" aria-hidden="true">{{ $i + 1 }}</span>
                    <span class="offer-step__name">{{ $step['name'] }}</span>
                    <span class="offer-step__reward num" dir="ltr">+{{ $step['reward'] }}</span>
                </li>
            @endforeach
        </ol>

        @if (count($steps) > 1)
            <p class="offer__foot">{{ __('wall.offer.steps_note', ['total' => $reward.' '.$currency]) }}</p>
        @endif
    </section>

    @if ($notes !== [])
        <section class="offer__section">
            <h2 class="offer__h">{{ __('wall.offer.how') }}</h2>
            <ul class="offer-notes">
                @foreach ($notes as $note)
                    <li>{{ $note }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="offer__section">
        <h2 class="offer__h">{{ __('wall.offer.requirements') }}</h2>

        <dl class="offer-facts">
            <div>
                <dt>{{ __('wall.offer.devices') }}</dt>
                <dd>{{ $devices === []
                    ? __('wall.offer.any_device')
                    : implode(', ', array_map(fn (string $device): string => __("wall.device.{$device}"), $devices)) }}</dd>
            </div>

            <div>
                <dt>{{ __('wall.offer.countries') }}</dt>

                {{-- Flags, not codes. "SE" is a puzzle to most of the people
                     this page is for; a flag is recognised without reading, and
                     the name is one hover away for the ones that are not. --}}
                <dd>
                    @if ($countries === [])
                        {{ __('wall.offer.worldwide') }}
                    @else
                        <span class="offer-flags">
                            @foreach ($shownCountries as $code)
                                @php $name = $countryName($code); @endphp
                                <span class="offer-flag" data-tip="{{ $name }}">
                                    {{-- Not lazy. Eight SVGs of a few hundred
                                         bytes each, and a lazy image inside a
                                         dialog that was display:none arrives
                                         after the dialog does — the user sees
                                         eight empty boxes first. --}}
                                    {{-- The fallback reads the code out of an
                                         attribute rather than having it
                                         interpolated into the JavaScript. An
                                         attribute value is HTML-decoded before
                                         the handler is parsed as script, so
                                         Blade's `&#039;` becomes a real quote at
                                         exactly the wrong moment and a stored
                                         apostrophe escapes the string literal.
                                         Nothing reaches code now, whatever the
                                         column holds. --}}
                                    <img src="/flags/{{ mb_strtolower($code) }}.svg" alt="{{ $name }}"
                                         width="21" height="16" decoding="async" data-code="{{ $code }}"
                                         onerror="this.replaceWith(document.createTextNode(this.dataset.code))">
                                </span>
                            @endforeach

                            @if (count($countries) > count($shownCountries))
                                @php
                                    // The tooltip is a tooltip, not the list
                                    // again: twelve more names and an ellipsis
                                    // rather than a panel-height wall of text.
                                    $rest = array_slice($countries, count($shownCountries));
                                    $tip = implode(', ', array_map($countryName, array_slice($rest, 0, 12)));
                                @endphp
                                <span class="offer-flag offer-flag--more num"
                                      data-tip="{{ $tip.(count($rest) > 12 ? ' …' : '') }}">
                                    +{{ count($rest) }}
                                </span>
                            @endif
                        </span>
                    @endif
                </dd>
            </div>

            @if (filled($offer->os_min_version))
                <div>
                    <dt>{{ __('wall.offer.os') }}</dt>
                    <dd dir="ltr">{{ $offer->os_min_version }}</dd>
                </div>
            @endif
        </dl>
    </section>

    {{-- Four static lines, and static is the point: all four are true of every
         offer on the wall, and each one is a real support ticket. Anything
         network-specific already renders above, under "Before you start", from
         `offers.instructions`. --}}
    <aside class="offer-warn">
        <h2 class="offer-warn__h">{{ __('wall.offer.notes') }}</h2>

        <ul>
            @foreach (['vpn', 'device', 'first_time', 'credit'] as $note)
                <li>{{ __("wall.offer.note.{$note}") }}</li>
            @endforeach
        </ul>
    </aside>

    {{-- Opens in a new tab, as the card used to. The offer runs on someone
         else's site and the wall is inside an iframe — navigating the frame
         away would leave the user with no way back to the wall.

         It sticks to the bottom edge, body scrolling under it: on a long
         multi-step offer the CTA sits below eleven steps and a notes box, and a
         user who read the terms had to scroll back down past all of it to act.
         Stickiness is CSS — this fragment carries no Alpine directives, because
         Alpine does not walk a subtree that arrives after boot. --}}
    <div class="offer__cta">
        <a class="wall-button offer__go" target="_blank" rel="noopener"
           href="{{ route('wall.click', $offer->id) }}?{{ $params }}"
           data-identity-link>
            {{ __('wall.offer.start') }}
        </a>

        <p class="offer__terms">{{ __('wall.offer.terms') }}</p>
    </div>
</article>
