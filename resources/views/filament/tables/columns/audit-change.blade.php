@php
    /** @var \App\Models\AuditLog $record */
    $record = $getRecord();
@endphp

<div class="ad-audit-change">
    @include('filament.admin.partials.audit-diff', ['record' => $record, 'limit' => 3])
</div>
