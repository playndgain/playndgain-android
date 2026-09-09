<x-mail::message>
<x-slot:hero>
<x-mail::hero eyebrow="Payout" title="Your payout is paid" amount="${{ $note->total }}" amount-label="Credit note {{ $note->number }}" />
</x-slot:hero>
The credit note is attached as a PDF. The same figure is the ledger entry on your Payments page.

<x-mail::facts :rows="[
    'Credit note' => $note->number,
    'Period' => $note->periodStart.' – '.$note->periodEnd,
    'Issued' => $note->issueDate,
]" />

<x-mail::button :url="\App\Support\Identity::url().'/dashboard/payments'">
Open Payments
</x-mail::button>
</x-mail::message>
