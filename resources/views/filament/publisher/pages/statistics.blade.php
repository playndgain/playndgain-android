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
        <div class="fi-stat-tile">
            <p class="fi-stat-tile-label">Earnings</p>
            <p class="fi-stat-tile-value is-gain">{{ \App\Services\Money\Money::display($totals['payout']) }}</p>
        </div>
        <div class="fi-stat-tile">
            <p class="fi-stat-tile-label">Clicks</p>
            <p class="fi-stat-tile-value">{{ number_format($totals['clicks']) }}</p>
        </div>
        <div class="fi-stat-tile">
            <p class="fi-stat-tile-label">Conversions</p>
            <p class="fi-stat-tile-value">{{ number_format($totals['conversions']) }}</p>
        </div>
        <div class="fi-stat-tile">
            <p class="fi-stat-tile-label">Conversion rate</p>
            <p class="fi-stat-tile-value">
                {{ $totals['clicks'] === 0 ? '—' : number_format($totals['conversions'] / $totals['clicks'] * 100, 2) . '%' }}
            </p>
        </div>
    </div>

    <div class="fi-plain-table">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th class="is-num">Clicks</th>
                    <th class="is-num">Conversions</th>
                    <th class="is-num">Earnings</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($this->series as $row)
                    <tr>
                        <td class="num">{{ $row['date'] }}</td>
                        <td class="is-num">{{ number_format($row['clicks']) }}</td>
                        <td class="is-num">{{ number_format($row['conversions']) }}</td>
                        <td class="is-num">{{ \App\Services\Money\Money::display($row['earnings']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        @foreach ($this->breakdowns as $title => $rows)
            <div class="fi-plain-table">
                <h3 class="fi-plain-table-title">{{ $title }}</h3>
                <table class="text-xs">
                    <thead>
                        <tr>
                            <th></th>
                            <th class="is-num">Clicks</th>
                            <th class="is-num">Conv</th>
                            <th class="is-num">CR</th>
                            <th class="is-num">Earnings</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr>
                                <td>{!! $row['html'] ? $row['label'] : e($row['label']) !!}</td>
                                <td class="is-num">{{ number_format($row['clicks']) }}</td>
                                <td class="is-num">{{ number_format($row['conversions']) }}</td>
                                <td class="is-num">{{ $row['cr'] }}</td>
                                <td class="is-num">{{ $row['earnings'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="is-empty">No data</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
