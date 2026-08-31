<x-filament-panels::page>
    <div class="fi-filter-bar">
        <label>
            <span class="fi-filter-label">Range</span>
            <select wire:model.live="range" class="fi-filter-field">
                @foreach ($this->rangeOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>

        <label>
            <span class="fi-filter-label">App</span>
            <select wire:model.live="appId" class="fi-filter-field">
                <option value="">All apps</option>
                @foreach ($this->appOptions as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>

        {{-- A div, not a <label>: the schema renders its own label element. --}}
        <div class="fi-filter-select">
            <span class="fi-filter-label">Country</span>
            {{ $this->getSchema('countryFilter') }}
        </div>
    </div>

    @php($totals = $this->totals)

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl bg-white/5 p-4">
            <p class="text-xs text-gray-400">Earnings</p>
            <p class="text-2xl font-semibold text-primary-400">${{ number_format((float) $totals['payout'], 2) }}</p>
        </div>
        <div class="rounded-xl bg-white/5 p-4">
            <p class="text-xs text-gray-400">Clicks</p>
            <p class="text-2xl font-semibold">{{ number_format($totals['clicks']) }}</p>
        </div>
        <div class="rounded-xl bg-white/5 p-4">
            <p class="text-xs text-gray-400">Conversions</p>
            <p class="text-2xl font-semibold">{{ number_format($totals['conversions']) }}</p>
        </div>
        <div class="rounded-xl bg-white/5 p-4">
            <p class="text-xs text-gray-400">Conversion rate</p>
            <p class="text-2xl font-semibold">
                {{ $totals['clicks'] === 0 ? '—' : number_format($totals['conversions'] / $totals['clicks'] * 100, 2) . '%' }}
            </p>
        </div>
    </div>

    <div class="overflow-x-auto rounded-xl bg-white/5 p-1">
        <table class="w-full text-sm">
            <thead class="text-gray-400">
                <tr class="text-left">
                    <th class="px-3 py-2">Date</th>
                    <th class="px-3 py-2 text-right">Clicks</th>
                    <th class="px-3 py-2 text-right">Conversions</th>
                    <th class="px-3 py-2 text-right">Earnings</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($this->series as $row)
                    <tr class="border-t border-white/5">
                        <td class="px-3 py-1.5 font-mono">{{ $row['date'] }}</td>
                        <td class="px-3 py-1.5 text-right">{{ number_format($row['clicks']) }}</td>
                        <td class="px-3 py-1.5 text-right">{{ number_format($row['conversions']) }}</td>
                        <td class="px-3 py-1.5 text-right">${{ number_format((float) $row['earnings'], 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        @foreach ($this->breakdowns as $title => $rows)
            <div class="rounded-xl bg-white/5 p-1">
                <h3 class="px-3 py-2 text-sm font-semibold">{{ $title }}</h3>
                <table class="w-full text-xs">
                    <thead class="text-gray-400">
                        <tr class="text-left">
                            <th class="px-3 py-1"></th>
                            <th class="px-3 py-1 text-right">Clicks</th>
                            <th class="px-3 py-1 text-right">Conv</th>
                            <th class="px-3 py-1 text-right">CR</th>
                            <th class="px-3 py-1 text-right">Earnings</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr class="border-t border-white/5">
                                <td class="px-3 py-1">{{ $row['label'] }}</td>
                                <td class="px-3 py-1 text-right">{{ number_format($row['clicks']) }}</td>
                                <td class="px-3 py-1 text-right">{{ number_format($row['conversions']) }}</td>
                                <td class="px-3 py-1 text-right">{{ $row['cr'] }}</td>
                                <td class="px-3 py-1 text-right">${{ $row['earnings'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-3 py-4 text-center text-gray-400">No data</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
