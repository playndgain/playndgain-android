{{-- text/plain, so the values are printed unescaped. --}}
Credit note {!! $note->number !!}

Your payout of ${!! $note->total !!} has been marked paid. The credit note is attached as a PDF.

Period: {!! $note->periodStart !!} – {!! $note->periodEnd !!}
