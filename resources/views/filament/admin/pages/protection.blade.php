<x-filament-panels::page>
    {{ $this->content }}

    <x-filament::section heading="Provider status">
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach ($this->providerStates as $state)
                <div @class([
                    'rounded-xl border p-4',
                    'border-success-500/40 bg-success-500/5' => $state['ok'],
                    'border-danger-500/40 bg-danger-500/5' => ! $state['ok'],
                ])>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm text-gray-300">{{ $state['name'] }}</span>
                        <span @class([
                            'size-2 shrink-0 rounded-full',
                            'bg-success-500' => $state['ok'],
                            'bg-danger-500' => ! $state['ok'],
                        ])></span>
                    </div>
                    <p class="mt-1 font-mono text-sm">
                        {{ $state['enabled'] ? 'enabled' : 'disabled' }}
                        · {{ $state['configured'] ? 'key on file' : 'no key' }}
                        · {{ $state['spent'] }} / {{ $state['cap'] !== null ? $state['cap'] : 'uncapped' }} today
                        · breaker {{ $state['breaker'] }}
                    </p>
                    @if ($state['last_error'])
                        <p class="mt-1 text-xs text-danger-400">{{ $state['last_error'] }} ({{ $state['last_error_at'] }})</p>
                    @endif
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section heading="Local databases">
        <x-slot name="afterHeader">
            {{ $this->updateDatabasesAction }}
        </x-slot>

        <div class="grid gap-3 sm:grid-cols-3">
            @foreach ($this->databases as $database)
                <div @class([
                    'rounded-xl border p-4',
                    'border-success-500/40 bg-success-500/5' => $database['ok'],
                    'border-danger-500/40 bg-danger-500/5' => ! $database['ok'],
                ])>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm text-gray-300">{{ $database['label'] }}</span>
                        <span @class([
                            'size-2 shrink-0 rounded-full',
                            'bg-success-500' => $database['ok'],
                            'bg-danger-500' => ! $database['ok'],
                        ])></span>
                    </div>
                    <p class="mt-1 font-mono text-sm">{{ $database['value'] }}</p>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section heading="Test an address">
        <p class="text-sm text-gray-400">
            Calls every enabled provider directly. It spends quota against the daily cap and counts toward the
            breaker like a real escalation — but unlike real traffic, it still calls a provider whose breaker is
            already open, which is the only way to check whether a vendor has recovered. A blank key or an
            exhausted cap is skipped, not called. The merged verdict cache real wall traffic reads is left
            untouched, so testing an address never installs a verdict that then governs real traffic.
            This tool does not check the consent-country setting: the address typed here is sent to every
            enabled vendor regardless of where it is from, because a manual operator test is a different
            processing basis to a wall render. Do not paste in an EU end user's IP from a fraud event without
            considering that first.
        </p>

        <div class="mt-3 flex items-end gap-2">
            <div class="max-w-xs flex-1">
                <x-filament::input.wrapper>
                    <x-filament::input type="text" wire:model="testIp" placeholder="8.8.8.8" />
                </x-filament::input.wrapper>
            </div>
            <x-filament::button wire:click="runTest">Test</x-filament::button>
        </div>

        @if ($this->testResults)
            <div class="fi-section mt-4 overflow-x-auto rounded-xl bg-white/5 p-1">
                <table class="w-full text-sm">
                    <thead class="text-gray-400">
                        <tr class="text-left">
                            <th class="px-3 py-2">Source</th>
                            <th class="px-3 py-2">Country</th>
                            <th class="px-3 py-2">ASN</th>
                            <th class="px-3 py-2">Organisation</th>
                            <th class="px-3 py-2">Proxy type</th>
                            <th class="px-3 py-2">Usage</th>
                            <th class="px-3 py-2">Threat</th>
                            <th class="px-3 py-2 text-right">Risk</th>
                            <th class="px-3 py-2 text-right">ms</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->testResults as $result)
                            <tr class="border-t border-white/5">
                                <td class="px-3 py-2">{{ $result['source'] }}</td>
                                @if (isset($result['note']))
                                    <td class="px-3 py-2 {{ $result['skipped'] ? 'text-gray-400' : 'text-danger-400' }}" colspan="6">{{ $result['note'] }}</td>
                                @else
                                    <td class="px-3 py-2 font-mono">{{ $result['country'] ?? '—' }}</td>
                                    <td class="px-3 py-2 font-mono">{{ $result['asn'] ?? '—' }}</td>
                                    <td class="px-3 py-2">{{ $result['organization'] ?? '—' }}</td>
                                    <td class="px-3 py-2 font-mono">{{ $result['proxy_type'] ?? '—' }}</td>
                                    <td class="px-3 py-2 font-mono">{{ $result['usage_type'] ?? '—' }}</td>
                                    <td class="px-3 py-2">{{ $result['threat'] ?? '—' }}</td>
                                    <td class="px-3 py-2 text-right">{{ $result['risk'] ?? '—' }}</td>
                                @endif
                                <td class="px-3 py-2 text-right font-mono">{{ $result['ms'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section heading="IP rules">
        <x-slot name="afterHeader">
            <x-filament::button tag="a" color="gray" :href="\App\Filament\Admin\Resources\FraudRules\FraudRuleResource::getUrl('index')">
                Fraud Rules
            </x-filament::button>
        </x-slot>

        <div class="fi-section overflow-x-auto rounded-xl bg-white/5 p-1">
            <table class="w-full text-sm">
                <thead class="text-gray-400">
                    <tr class="text-left">
                        <th class="px-3 py-2">Key</th>
                        <th class="px-3 py-2">Name</th>
                        <th class="px-3 py-2">Action</th>
                        <th class="px-3 py-2 text-right">Weight</th>
                        <th class="px-3 py-2">Enabled</th>
                        <th class="px-3 py-2">Gates render</th>
                        <th class="px-3 py-2 text-right">Fired 24h</th>
                        <th class="px-3 py-2 text-right">Fired 7d</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->rules as $rule)
                        <tr class="border-t border-white/5">
                            <td class="px-3 py-2 font-mono">{{ $rule['key'] }}</td>
                            <td class="px-3 py-2">{{ $rule['name'] }}</td>
                            <td class="px-3 py-2">{{ $rule['action'] }}</td>
                            <td class="px-3 py-2 text-right">{{ $rule['weight'] }}</td>
                            <td class="px-3 py-2">{{ $rule['enabled'] ? 'yes' : 'no' }}</td>
                            <td class="px-3 py-2">{{ $rule['gates_render'] ? 'yes' : 'no' }}</td>
                            <td class="px-3 py-2 text-right">{{ $rule['fired_24h'] }}</td>
                            <td class="px-3 py-2 text-right">{{ $rule['fired_7d'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-3 py-8 text-center text-gray-400">No render-gate rules configured.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <p class="mt-2 text-xs text-gray-400">
            "Gates render" means enabled and blocking, not "always refuses a render": vpn_proxy_ip still triggers
            on an unclassified proxy (a stale database or a terse provider) but only refuses the render for an
            explicit VPN/WEB/PUB/SES/CPN match — the unclassified trigger still flags at click and still writes
            a fraud_event.
        </p>
    </x-filament::section>
</x-filament-panels::page>
