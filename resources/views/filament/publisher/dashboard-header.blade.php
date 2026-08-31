{{--
    Dashboard header — docs/08 §3.

    The clock and the Live dot are the same claim made twice: this page is
    showing you now, not a cached hour-old snapshot. The clock runs in the
    publisher's own timezone so it agrees with every date the panel renders
    beside it.
--}}
<div class="flex flex-col justify-center gap-1">
    <h2 class="text-xl font-semibold text-gray-50">
        Welcome back, {{ \Illuminate\Support\Str::before($name, ' ') }}.
    </h2>

    <div class="flex items-center gap-3 text-sm text-gray-400">
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
            class="font-mono tabular-nums"
        >{{ $timezone }}</span>

        <span class="inline-flex items-center gap-1.5 text-success-400">
            <span class="relative flex size-2">
                <span class="absolute inline-flex size-full animate-ping rounded-full bg-success-400 opacity-75"></span>
                <span class="relative inline-flex size-2 rounded-full bg-success-500"></span>
            </span>
            Live
        </span>
    </div>
</div>
