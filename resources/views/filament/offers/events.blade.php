@php
    /** @var \App\Models\Offer $offer */
    /** @var list<array{name: string, payout: string, points: ?string}> $events */
    /** @var bool $publisherFacing */
    /** @var ?string $rewardNote */

    $notes = \App\Support\Plain::lines($offer->instructions);
    $lead = \App\Support\Plain::text($offer->description);
    $count = count($events);
    $initial = mb_substr((string) $offer->name, 0, 1);
    $type = $offer->conversion_type?->value;
@endphp

<div class="offer-events">
    <header class="offer-events__head">
        <span class="offer-events__tile" aria-hidden="true">
            <span class="offer-events__letter">{{ $initial }}</span>
            @if (filled($offer->icon_url))
                <img class="offer-events__icon" src="{{ $offer->icon_url }}" alt="" width="48" height="48"
                     decoding="async" onerror="this.remove()">
            @endif
        </span>

        <div class="offer-events__lede">
            <p class="offer-events__kicker">
                @if (filled($type))
                    <span class="offer-events__type">{{ $type }}</span>
                    <span aria-hidden="true">·</span>
                @endif
                {{ $count }} {{ $count === 1 ? 'event' : 'events' }}
                @if ($offer->is_multi_reward)
                    <span aria-hidden="true">·</span>
                    pays more than once
                @endif
            </p>

            @if (filled($lead))
                <p class="offer-events__lead">{{ $lead }}</p>
            @endif
        </div>
    </header>

    <ol class="offer-events__list">
        @foreach ($events as $index => $event)
            <li class="offer-events__row">
                <span class="offer-events__n num" aria-hidden="true">{{ $index + 1 }}</span>
                <span class="offer-events__name">{{ $event['name'] }}</span>
                <span class="offer-events__pay num" dir="ltr">
                    <span class="offer-events__usd">+{{ $event['payout'] }}</span>
                    @if (filled($event['points'] ?? null))
                        <span class="offer-events__points">+{{ $event['points'] }}</span>
                    @endif
                </span>
            </li>
        @endforeach
    </ol>

    <p class="offer-events__note">
        @if ($count > 1)
            Each event pays on its own. The user can earn more than once.
        @else
            This offer pays once.
        @endif
        @if ($publisherFacing)
            Amounts shown are what you earn.
        @endif
        @if (filled($rewardNote ?? null))
            {{ $rewardNote }}
        @endif
    </p>

    @if ($notes !== [])
        <section class="offer-events__how" aria-labelledby="offer-events-how">
            <h3 id="offer-events-how">How to convert</h3>
            <ul>
                @foreach ($notes as $note)
                    <li>{{ $note }}</li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
