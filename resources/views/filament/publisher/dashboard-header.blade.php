{{--
    Dashboard header — docs/08 §3.

    The clock and the Live dot are the same claim made twice: this page is
    showing you now, not a cached hour-old snapshot. The clock runs in the
    publisher's own timezone so it agrees with every date the panel renders
    beside it. The dot is amber, like the live dot on the landing page — it is
    a state, not money.

    No emoji, no tile, no wave: the greeting is type on the sheet, the same
    as every other page title in the panel. The block is the first row of the
    header card (.pg-dash-head, styled in filament/theme.css); the app
    selector is the second.
--}}
<div class="pg-dash-hello">
    <div class="pg-dash-hello__text">
        <h2 class="pg-dash-hello__title">
            Welcome back, {{ \Illuminate\Support\Str::before($name, ' ') }}
        </h2>

        <div class="pg-dash-hello__meta">
            <span
                x-data="{
                    clock: '',
                    start() {
                        const format = new Intl.DateTimeFormat('en-GB', {
                            dateStyle: 'medium',
                            timeStyle: 'medium',
                            timeZone: @js($timezone),
                        })

                        const tick = () => this.clock = format.format(new Date())

                        tick()
                        setInterval(tick, 1000)
                    },
                }"
                x-init="start()"
                x-text="clock"
                class="num pg-dash-hello__clock"
            >{{ $timezone }}</span>

            <span class="pg-dash-hello__tz">{{ str_replace('_', ' ', $timezone) }}</span>
        </div>
    </div>

    <span class="pg-dash-live">
        <span class="pg-dash-live__dot" aria-hidden="true"></span>
        Live
    </span>
</div>
