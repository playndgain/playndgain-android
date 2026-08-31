@php
    $publisher = \App\Filament\Publisher\Support\CurrentPublisher::get();
    $status = $publisher?->status;
@endphp

@if ($publisher === null)
    <div class="mx-auto max-w-7xl px-4 pt-4">
        <div class="rounded-xl border border-warning-500/40 bg-warning-500/10 px-4 py-3 text-sm">
            <strong class="font-semibold">You are in the publisher panel as an admin.</strong>
            This login has no publisher account, so you cannot create apps here.
            Impersonate a publisher from the admin panel, or sign in as one.
        </div>
    </div>
@elseif ($status === \App\Enums\PublisherStatus::Pending)
    <div class="mx-auto max-w-7xl px-4 pt-4">
        <div class="rounded-xl border border-warning-500/40 bg-warning-500/10 px-4 py-3 text-sm">
            <strong class="font-semibold">Your account is under review.</strong>
            You can create apps now — they'll go live once approved. Fire a test conversion in the meantime;
            the wall serves live offers only after a human approves you.
        </div>
    </div>
@elseif ($status === \App\Enums\PublisherStatus::Suspended)
    <div class="mx-auto max-w-7xl px-4 pt-4">
        <div class="rounded-xl border border-danger-500/40 bg-danger-500/10 px-4 py-3 text-sm">
            <strong class="font-semibold">Your account is suspended.</strong>
            The panel is read-only and withdrawals are blocked.
            @if (filled($publisher?->notes))
                <span class="block pt-1 text-gray-300">{{ \Illuminate\Support\Str::limit($publisher->notes, 300) }}</span>
            @endif
            <a href="mailto:{{ \App\Support\Identity::supportEmail() }}"
               class="underline underline-offset-2">Contact support</a>
        </div>
    </div>
@endif
