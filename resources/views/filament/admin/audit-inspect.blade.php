@php
    /** @var \App\Models\AuditLog $record */
    /** @var ?string $subjectUrl */
    $family = $record->family();
    $when = $record->created_at;
@endphp

<article class="ad-audit-inspect ad-audit-inspect--{{ $family->value }}">
    <header class="ad-audit-inspect__head">
        <p class="ad-audit-inspect__family">{{ $family->getLabel() }}</p>
        <h3 class="ad-audit-inspect__title">{{ $record->label() }}</h3>
        <p class="ad-audit-inspect__lede">
            {{ $record->user?->name ?? 'Someone' }}
            <span aria-hidden="true">·</span>
            {{ $when?->diffForHumans() }}
            @if (filled($record->ip))
                <span aria-hidden="true">·</span>
                <span class="num">{{ $record->ip }}</span>
            @endif
        </p>
    </header>

    <dl class="ad-audit-inspect__facts">
        <div>
            <dt>Actor</dt>
            <dd>
                {{ $record->user?->name ?? 'system' }}
                @if (filled($record->user?->email))
                    <span class="ad-audit-inspect__sub">{{ $record->user->email }}</span>
                @endif
            </dd>
        </div>
        <div>
            <dt>Subject</dt>
            <dd>
                @if (filled($subjectUrl))
                    <a href="{{ $subjectUrl }}">{{ $record->subjectLabel() }}</a>
                @else
                    {{ $record->subjectLabel() }}
                @endif
                <span class="ad-audit-inspect__sub">{{ class_basename($record->subject_type) }}</span>
            </dd>
        </div>
        <div>
            <dt>When</dt>
            <dd>
                {{ $when?->timezone(config('app.timezone'))->format('M j, Y H:i:s') }}
                <span class="ad-audit-inspect__sub">{{ $when?->toIso8601String() }}</span>
            </dd>
        </div>
        <div>
            <dt>Action</dt>
            <dd><code class="ad-audit-inspect__code">{{ $record->action }}</code></dd>
        </div>
    </dl>

    <section class="ad-audit-inspect__change" aria-labelledby="ad-audit-change">
        <h4 id="ad-audit-change">What changed</h4>
        @include('filament.admin.partials.audit-diff', ['record' => $record, 'limit' => 40])
    </section>
</article>
