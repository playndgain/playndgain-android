<x-filament-panels::page>
    <div class="fi-filter-bar">
        <label>
            <span class="fi-filter-label">Showing inventory for</span>
            <select wire:model.live="appId" class="fi-filter-field">
                @foreach ($this->appOptions as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>

        <p class="max-w-xl pb-2 text-xs text-gray-400">
            Eligibility differs per app — your blacklists and blocked countries are per placement.
            Payout shown is yours after rev share.
        </p>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
