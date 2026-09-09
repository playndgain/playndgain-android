<x-filament-panels::page>
    <div class="fi-filter-bar">
        <label>
            <span class="fi-filter-label">Period</span>
            <select wire:model.live="period" class="fi-filter-field">
                <option value="day">By day</option>
                <option value="month">By month</option>
            </select>
        </label>

        <label>
            <span class="fi-filter-label">Group by</span>
            <select wire:model.live="groupBy" class="fi-filter-field">
                <option value="none">Everything</option>
                <option value="network">Network</option>
                <option value="publisher">Publisher</option>
            </select>
        </label>

        <label>
            <span class="fi-filter-label">Range</span>
            <select wire:model.live="days" class="fi-filter-field">
                <option value="30">Last 30 days</option>
                <option value="90">Last 90 days</option>
                <option value="365">Last year</option>
            </select>
        </label>
    </div>

    <div class="fi-plain-table">
        <table>
            <thead>
                <tr>
                    <th>Period</th>
                    @if ($groupBy !== 'none')
                        <th>{{ ucfirst($groupBy) }}</th>
                    @endif
                    <th class="is-num">Conversions</th>
                    <th class="is-num">Reversals</th>
                    <th class="is-num">Reversal rate</th>
                    <th class="is-num">Revenue</th>
                    <th class="is-num">Payout</th>
                    <th class="is-num">Profit</th>
                    <th class="is-num">Margin</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->rows as $row)
                    <tr>
                        <td class="num">{{ $row['bucket'] }}</td>
                        @if ($groupBy !== 'none')
                            <td>{{ $row['group'] }}</td>
                        @endif
                        <td class="is-num">{{ $row['conversions'] }}</td>
                        <td class="is-num">{{ $row['reversals'] }}</td>
                        <td class="is-num">{{ $row['reversal_rate'] }}</td>
                        <td class="is-num">${{ $row['revenue'] }}</td>
                        <td class="is-num">${{ $row['payout'] }}</td>
                        <td class="is-num is-gain">${{ $row['profit'] }}</td>
                        <td class="is-num">{{ $row['margin'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="is-empty">
                            No aggregated stats for this range yet. <code>php artisan stats:aggregate</code> fills this table.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
