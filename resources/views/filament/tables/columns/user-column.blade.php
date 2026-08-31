{{-- Identity cell: initials mark, name, email, optional company.
     ViewColumn-style padding — Filament zeroes the cell and lets each
     column dress itself, same as geo-stack. --}}
@php
    use App\Models\App;
    use App\Models\Publisher;
    use App\Models\User;

    $user = $getState();

    if (! $user instanceof User) {
        $user = $record instanceof User ? $record : ($record->user ?? $record->publisher?->user ?? null);
    }

    $company = match (true) {
        $record instanceof Publisher => $record->company_name,
        $record instanceof App => $record->publisher?->company_name,
        default => null,
    };

    $initials = '—';

    if ($user instanceof User && filled($user->name)) {
        $initials = collect(preg_split('/\s+/', trim($user->name)) ?: [])
            ->filter()
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->take(2)
            ->implode('');
    }
@endphp

@if (! $user instanceof User)
    <p class="fi-ta-placeholder ad-user">—</p>
@else
    <div class="ad-user">
        <span class="ad-user__mark" aria-hidden="true">{{ $initials }}</span>
        <div class="ad-user__body">
            <span class="ad-user__name">{{ $user->name }}</span>
            @if (filled($user->email))
                <span class="ad-user__email"
                      role="button"
                      tabindex="0"
                      title="Copy email"
                      x-data="{
                          copied: false,
                          copy() {
                              navigator.clipboard.writeText(@js($user->email));
                              this.copied = true;
                              setTimeout(() => this.copied = false, 1400);
                          },
                      }"
                      x-on:click.stop="copy()"
                      x-on:keydown.enter.stop="copy()"
                      x-bind:data-copied="copied">
                    <span x-show="! copied">{{ $user->email }}</span>
                    <span x-cloak x-show="copied">Copied</span>
                </span>
            @endif
            @if (filled($company) && $company !== $user->name)
                <span class="ad-user__meta">{{ $company }}</span>
            @endif
        </div>
    </div>
@endif
