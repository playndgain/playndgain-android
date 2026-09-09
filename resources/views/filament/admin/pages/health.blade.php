<x-filament-panels::page>
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($this->checks as $check)
            <div @class(['fi-check-card', 'is-down' => ! $check['ok']])>
                <div class="fi-check-card-row">
                    <span>{{ $check['label'] }}</span>
                    <span class="fi-check-card-dot" aria-hidden="true"></span>
                </div>
                <p class="fi-check-card-value">{{ $check['value'] }}</p>
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
