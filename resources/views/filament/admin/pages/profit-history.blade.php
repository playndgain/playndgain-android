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

    <div class="fi-section overflow-x-auto rounded-xl bg-white/5 p-1">
        <table class="w-full text-sm">
            <thead class="text-gray-400">
                <tr class="text-left">
                    <th class="px-3 py-2">Period</th>
                    @if ($groupBy !== 'none')
                        <th class="px-3 py-2">{{ ucfirst($groupBy) }}</th>
                    @endif
                    <th class="px-3 py-2 text-right">Conversions</th>
                    <th class="px-3 py-2 text-right">Reversals</th>
                    <th class="px-3 py-2 text-right">Reversal rate</th>
                    <th class="px-3 py-2 text-right">Revenue</th>
                    <th class="px-3 py-2 text-right">Payout</th>
                    <th class="px-3 py-2 text-right">Profit</th>
                    <th class="px-3 py-2 text-right">Margin</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->rows as $row)
                    <tr class="border-t border-white/5">
                        <td class="px-3 py-2 font-mono">{{ $row['bucket'] }}</td>
                        @if ($groupBy !== 'none')
                            <td class="px-3 py-2">{{ $row['group'] }}</td>
                        @endif
                        <td class="px-3 py-2 text-right">{{ $row['conversions'] }}</td>
                        <td class="px-3 py-2 text-right">{{ $row['reversals'] }}</td>
                        <td class="px-3 py-2 text-right">{{ $row['reversal_rate'] }}</td>
                        <td class="px-3 py-2 text-right">${{ $row['revenue'] }}</td>
                        <td class="px-3 py-2 text-right">${{ $row['payout'] }}</td>
                        <td class="px-3 py-2 text-right font-semibold text-primary-400">${{ $row['profit'] }}</td>
                        <td class="px-3 py-2 text-right">{{ $row['margin'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-3 py-8 text-center text-gray-400">
                            No aggregated stats for this range yet. <code>php artisan stats:aggregate</code> fills this table.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
