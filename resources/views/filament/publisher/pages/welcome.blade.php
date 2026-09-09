{{--
    The one-time credential handover — docs/08 §15.

    The secret is printed in full here and nowhere else. Everything about the
    layout says so: the warning sits above the value, not below it, and the
    copy button is the largest control on the card.
--}}
<x-filament-panels::page>
    <div class="mx-auto w-full max-w-2xl space-y-6">
        <div class="fi-brand-banner">
            <strong>Copy the secret key now.</strong>
            It is shown once, on this screen. After you leave, the only way to see a secret again is to
            regenerate it — which invalidates every signature you have already embedded.
        </div>

        <div class="space-y-5 rounded-card border border-panel-line bg-panel-card p-6">
            <p class="text-sm text-fog-300">
                <strong class="font-semibold text-fog-50">{{ $this->credentials['app_name'] }}</strong>
                is created and pending review, alongside your account.
            </p>

            @foreach ([
                ['label' => 'App ID', 'value' => (string) $this->credentials['app_id'], 'mono' => false],
                ['label' => 'Secret key', 'value' => $this->credentials['secret'], 'mono' => true],
            ] as $field)
                <div x-data="{ copied: false }" class="space-y-1.5">
                    <span class="label">{{ $field['label'] }}</span>

                    <div class="flex items-center gap-2">
                        <code @class([
                            'flex-1 overflow-x-auto rounded-control border border-panel-line bg-panel-tile px-3 py-2 text-sm text-fog-50',
                            'font-mono break-all' => $field['mono'],
                            'num' => ! $field['mono'],
                        ])>{{ $field['value'] }}</code>

                        <x-filament::button
                            size="sm"
                            color="gray"
                            x-on:click="
                                window.navigator.clipboard.writeText(@js($field['value']));
                                copied = true;
                                setTimeout(() => copied = false, 2000)
                            "
                        >
                            <span x-show="! copied">Copy</span>
                            <span x-show="copied" x-cloak>Copied</span>
                        </x-filament::button>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="space-y-3 text-sm text-fog-300">
            <p>
                <strong class="font-semibold text-fog-50">What happens next.</strong>
                We have emailed you a verification link — confirm the address and the rest of the dashboard opens.
                From there you can configure the app and fire a test postback straight away.
            </p>
            <p>
                Your account stays pending until a person reads it. The wall serves live offers only after that;
                everything else is yours now.
            </p>
        </div>

        <div class="flex gap-3">
            <x-filament::button tag="a" :href="\App\Filament\Publisher\Resources\Apps\AppResource::getUrl('index')">
                Go to my app
            </x-filament::button>
            <x-filament::button tag="a" color="gray" :href="filament()->getUrl()">
                Dashboard
            </x-filament::button>
        </div>
    </div>
</x-filament-panels::page>
