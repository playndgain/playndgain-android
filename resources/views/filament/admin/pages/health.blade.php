<x-filament-panels::page>
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($this->checks as $check)
            <div @class([
                'rounded-xl border p-4',
                'border-success-500/40 bg-success-500/5' => $check['ok'],
                'border-danger-500/40 bg-danger-500/5' => ! $check['ok'],
            ])>
                <div class="flex items-center justify-between gap-3">
                    <span class="text-sm text-gray-300">{{ $check['label'] }}</span>
                    <span @class([
                        'size-2 shrink-0 rounded-full',
                        'bg-success-500' => $check['ok'],
                        'bg-danger-500' => ! $check['ok'],
                    ])></span>
                </div>
                <p class="mt-1 font-mono text-sm">{{ $check['value'] }}</p>
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
