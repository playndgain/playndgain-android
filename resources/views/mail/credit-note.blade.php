{{-- text/plain, so the values are printed unescaped. --}}
Credit note {!! $note->number !!}

Your payout of ${!! $note->total !!} is marked paid. The credit note is attached as a PDF, and the same figure is the ledger entry you can see in your dashboard.

Period: {!! $note->periodStart !!} – {!! $note->periodEnd !!}
