@php
    /** @var \App\Models\AuditLog $record */
    $limit = $limit ?? 4;
    $changes = $record->changes();
    $shown = array_slice($changes, 0, $limit);
    $rest = count($changes) - count($shown);
@endphp

@if ($changes === [])
    <p class="ad-audit-diff__empty">No payload</p>
@else
    <ul class="ad-audit-diff" @if ($rest > 0) title="{{ $rest }} more {{ $rest === 1 ? 'field' : 'fields' }}" @endif>
        @foreach ($shown as $change)
            @php
                $before = \App\Models\AuditLog::displayValue($change['before']);
                $after = \App\Models\AuditLog::displayValue($change['after']);
                $same = $before === $after;
            @endphp
            <li class="ad-audit-diff__row">
                <span class="ad-audit-diff__key">{{ $change['key'] }}</span>
                @if ($same)
                    <span class="ad-audit-diff__after">{{ $after }}</span>
                @else
                    <span class="ad-audit-diff__pair">
                        <span class="ad-audit-diff__before">{{ $before }}</span>
                        <span class="ad-audit-diff__arrow" aria-hidden="true">→</span>
                        <span class="ad-audit-diff__after">{{ $after }}</span>
                    </span>
                @endif
            </li>
        @endforeach
        @if ($rest > 0)
            <li class="ad-audit-diff__more">+{{ $rest }} more</li>
        @endif
    </ul>
@endif
