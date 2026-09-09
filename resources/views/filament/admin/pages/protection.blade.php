<x-filament-panels::page>
    {{ $this->content }}

    <x-filament::section heading="Provider status">
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach ($this->providerStates as $state)
                <div @class(['fi-check-card', 'is-down' => ! $state['ok']])>
                    <div class="fi-check-card-row">
                        <span>{{ $state['name'] }}</span>
                        <span class="fi-check-card-dot" aria-hidden="true"></span>
                    </div>
                    <p class="fi-check-card-value">
                        {{ $state['enabled'] ? 'enabled' : 'disabled' }}
                        · {{ $state['configured'] ? 'key on file' : 'no key' }}
                        · {{ $state['spent'] }} / {{ $state['cap'] !== null ? $state['cap'] : 'uncapped' }} today
                        · breaker {{ $state['breaker'] }}
                    </p>
                    @if ($state['last_error'])
                        <p class="fi-check-card-error">{{ $state['last_error'] }} ({{ $state['last_error_at'] }})</p>
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
                <div @class(['fi-check-card', 'is-down' => ! $database['ok']])>
                    <div class="fi-check-card-row">
                        <span>{{ $database['label'] }}</span>
                        <span class="fi-check-card-dot" aria-hidden="true"></span>
                    </div>
                    <p class="fi-check-card-value">{{ $database['value'] }}</p>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section heading="Test an address">
        <p class="text-sm text-fog-400">
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
            <div class="fi-plain-table mt-4">
                <table>
                    <thead>
                        <tr>
                            <th>Source</th>
                            <th>Country</th>
                            <th>ASN</th>
                            <th>Organisation</th>
                            <th>Proxy type</th>
                            <th>Usage</th>
                            <th>Threat</th>
                            <th class="is-num">Risk</th>
                            <th class="is-num">ms</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->testResults as $result)
                            <tr>
                                <td>{{ $result['source'] }}</td>
                                @if (isset($result['note']))
                                    <td class="{{ $result['skipped'] ? 'is-dim' : 'is-loss' }}" colspan="6">{{ $result['note'] }}</td>
                                @else
                                    <td class="num">{{ $result['country'] ?? '—' }}</td>
                                    <td class="num">{{ $result['asn'] ?? '—' }}</td>
                                    <td>{{ $result['organization'] ?? '—' }}</td>
                                    <td class="num">{{ $result['proxy_type'] ?? '—' }}</td>
                                    <td class="num">{{ $result['usage_type'] ?? '—' }}</td>
                                    <td>{{ $result['threat'] ?? '—' }}</td>
                                    <td class="is-num">{{ $result['risk'] ?? '—' }}</td>
                                @endif
                                <td class="is-num">{{ $result['ms'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    {{-- What the risk threshold is actually costing. It sits directly above the
         rules table because the number an operator reads here is the number they
         then go and change there. --}}
    <x-filament::section heading="Risk score distribution">
        <x-slot name="afterHeader">
            <div class="fi-score-windows">
                @foreach ($this->scoreDistribution['windows'] as $days)
                    <button
                        type="button"
                        wire:click="setScoreWindow({{ $days }})"
                        @class(['fi-score-window', 'is-on' => $this->scoreDistribution['window'] === $days])
                        aria-pressed="{{ $this->scoreDistribution['window'] === $days ? 'true' : 'false' }}"
                    >{{ $days === 1 ? '24h' : $days . 'd' }}</button>
                @endforeach
            </div>
        </x-slot>

        @if ($this->scoreDistribution['total'] === 0)
            <p class="text-sm text-fog-400">
                No scored addresses yet. Only a third-party provider returns a risk score — the local
                databases carry none — so this stays empty until ProxyCheck or IPQualityScore is
                configured and enabled above, and then fills as the wall is rendered.
            </p>
        @else
            <div class="fi-score-chart" role="img"
                aria-label="Risk scores over the last {{ $this->scoreDistribution['window'] }} day(s)">
                @foreach ($this->scoreDistribution['bands'] as $band)
                    <div class="fi-score-col">
                        <span class="fi-score-count">{{ $band['count'] ?: '' }}</span>
                        <span class="fi-score-track">
                            <span @class(['fi-score-bar', 'is-refused' => $band['refused']])
                                  style="height: {{ $band['height'] }}%"></span>
                        </span>
                        <span class="fi-score-label">{{ $band['label'] }}</span>
                    </div>
                @endforeach
            </div>

            <p class="fi-score-summary">
                @if ($this->scoreDistribution['threshold'] === null)
                    No <span class="num">ip_risk_score</span> threshold is configured, so nothing here is refused.
                @else
                    Threshold <strong>{{ $this->scoreDistribution['threshold'] }}</strong> —
                    <strong>{{ number_format($this->scoreDistribution['refused']) }}</strong>
                    of {{ number_format($this->scoreDistribution['total']) }} scored renders
                    ({{ $this->scoreDistribution['share'] }}%)
                    @if ($this->scoreDistribution['blocking'])
                        are refused the wall entirely.
                    @else
                        would be refused if this rule were enabled and set to block. It is not, so they are being served.
                    @endif
                @endif
            </p>

            <p class="mt-2 text-xs text-fog-500">
                Sampled at render, so refused and served addresses are both counted — a click row would miss
                everything above the threshold and a fraud event would miss everything below it. Counts live in
                the cache alongside the provider quota counters, so a cache flush restarts the history; every
                actual block is still in Fraud Events.
            </p>
        @endif
    </x-filament::section>

    <x-filament::section heading="IP rules">
        <x-slot name="afterHeader">
            <x-filament::button tag="a" color="gray" :href="\App\Filament\Admin\Resources\FraudRules\FraudRuleResource::getUrl('index')">
                Fraud Rules
            </x-filament::button>
        </x-slot>

        <div class="fi-plain-table">
            <table>
                <thead>
                    <tr>
                        <th>Key</th>
                        <th>Name</th>
                        <th>Action</th>
                        <th class="is-num">Weight</th>
                        <th>Enabled</th>
                        <th>Gates render</th>
                        <th class="is-num">Fired 24h</th>
                        <th class="is-num">Fired 7d</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->rules as $rule)
                        <tr>
                            <td class="num">{{ $rule['key'] }}</td>
                            <td>{{ $rule['name'] }}</td>
                            <td>{{ $rule['action'] }}</td>
                            <td class="is-num">{{ $rule['weight'] }}</td>
                            <td>{{ $rule['enabled'] ? 'yes' : 'no' }}</td>
                            <td>{{ $rule['gates_render'] ? 'yes' : 'no' }}</td>
                            <td class="is-num">{{ $rule['fired_24h'] }}</td>
                            <td class="is-num">{{ $rule['fired_7d'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="is-empty">No render-gate rules configured.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <p class="mt-2 text-xs text-fog-500">
            "Gates render" means enabled and blocking, not "always refuses a render": vpn_proxy_ip still triggers
            on an unclassified proxy (a stale database or a terse provider) but only refuses the render for an
            explicit VPN/WEB/PUB/SES/CPN match — the unclassified trigger still flags at click and still writes
            a fraud_event.
        </p>
    </x-filament::section>
</x-filament-panels::page>
