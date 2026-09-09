<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $note->number }}</title>
<style>
    @page { margin: 22mm 18mm 22mm 18mm; }
    body {
        font-family: DejaVu Sans, Helvetica, Arial, sans-serif;
        font-size: 10px;
        color: #1a1a1a;
        line-height: 1.45;
    }
    .masthead {
        font-size: 9px;
        color: #555;
        border-bottom: 1px solid #222;
        padding-bottom: 8px;
        margin-bottom: 22px;
    }
    .row { width: 100%; }
    .left { width: 55%; vertical-align: top; }
    .right { width: 45%; vertical-align: top; }
    .payee { font-size: 11px; line-height: 1.5; }
    .payee strong { font-size: 12px; }
    .meta { width: 100%; border-collapse: collapse; }
    .meta td { padding: 2px 0; }
    .meta .k { color: #555; width: 46%; }
    .meta .v { text-align: right; font-weight: bold; }
    h1 {
        font-size: 16px;
        margin: 22px 0 6px;
        letter-spacing: -0.2px;
    }
    .period { color: #444; margin-bottom: 18px; }
    table.lines { width: 100%; border-collapse: collapse; margin-top: 8px; }
    table.lines th {
        text-align: left;
        border-bottom: 1px solid #222;
        padding: 6px 0;
        font-size: 9px;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #444;
    }
    table.lines th.num, table.lines td.num { text-align: right; }
    table.lines td { padding: 8px 0; border-bottom: 1px solid #e4e4e4; }
    table.totals { width: 42%; margin-left: auto; margin-top: 10px; border-collapse: collapse; }
    table.totals td { padding: 4px 0; }
    table.totals .k { color: #444; }
    table.totals .v { text-align: right; }
    table.totals .grand td { border-top: 1px solid #222; font-weight: bold; padding-top: 8px; font-size: 12px; }
    .pay {
        margin-top: 22px;
        padding: 10px 12px;
        background: #f4f4f4;
        border-left: 3px solid #DFC08A;
    }
    .close { margin-top: 22px; color: #333; }
    .sign { margin-top: 16px; }
    .foot {
        margin-top: 36px;
        border-top: 1px solid #222;
        padding-top: 10px;
        width: 100%;
        font-size: 8.5px;
        color: #444;
    }
    .foot td { vertical-align: top; width: 33%; padding-right: 12px; }
    .foot strong { color: #1a1a1a; display: block; margin-bottom: 4px; }
</style>
</head>
<body>
@php $issuer = $note->issuer; @endphp

<div class="masthead">
    {{ $issuer['name'] }}
    @if ($issuer['address'] !== '') • {{ $issuer['address'] }} @endif
    @if ($issuer['city'] !== '' || $issuer['country'] !== '')
        • {{ trim($issuer['city'].' '.$issuer['country']) }}
    @endif
</div>

<table class="row">
    <tr>
        <td class="left">
            <div class="payee">
                <strong>{{ $note->payeeName }}</strong><br>
                @if ($note->payeeAddress !== '') {{ $note->payeeAddress }}<br> @endif
                @if ($note->payeeCityLine !== '') {{ $note->payeeCityLine }}<br> @endif
                @if ($note->payeeCountry !== '') {{ $note->payeeCountry }} @endif
            </div>
        </td>
        <td class="right">
            <table class="meta">
                <tr><td class="k">Credit Note No.:</td><td class="v">{{ $note->number }}</td></tr>
                <tr><td class="k">Publisher ID:</td><td class="v">{{ $note->publisherId }}</td></tr>
                <tr><td class="k">Issue Date:</td><td class="v">{{ $note->issueDate }}</td></tr>
                <tr><td class="k">Due Date:</td><td class="v">{{ $note->dueDate }}</td></tr>
                <tr><td class="k">VAT No.:</td><td class="v">{{ $note->payeeVat !== '' ? $note->payeeVat : ($issuer['vat'] !== '' ? $issuer['vat'] : '—') }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<h1>Credit Note No.: {{ $note->number }}</h1>
<div class="period">
    Billing Period: {{ $note->periodStart }} - {{ $note->periodEnd }}<br>
    Billing Contact Email: {{ $note->billingEmail }}
</div>

<table class="lines">
    <thead>
        <tr>
            <th>Description</th>
            <th class="num">Amount</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($note->lines as $line)
            <tr>
                <td>{{ $line['description'] }}</td>
                <td class="num">$ {{ $line['amount'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="totals">
    <tr>
        <td class="k">Total excl. VAT</td>
        <td class="v">$ {{ $note->subtotal }}</td>
    </tr>
    <tr>
        <td class="k">VAT {{ rtrim(rtrim($note->vatRate, '0'), '.') }}%</td>
        <td class="v">$ {{ $note->vatAmount }}</td>
    </tr>
    <tr class="grand">
        <td>Total</td>
        <td class="v">$ {{ $note->total }}</td>
    </tr>
</table>

<div class="pay">
    We will transfer the amount via:<br>
    <strong>{{ $note->paymentInstruction }}</strong>
</div>

<p class="close">
    We look forward to continuing this successful business relationship and appreciate your confidence in our service.
</p>
<p class="sign">
    Sincerely,<br>
    <strong>{{ $issuer['name'] }}</strong>
</p>

<table class="foot">
    <tr>
        <td>
            <strong>{{ $issuer['name'] }}</strong>
            Address:<br>
            {{ $issuer['address'] }}<br>
            {{ trim($issuer['city'].' '.$issuer['country']) }}
        </td>
        <td>
            <strong>Contact</strong>
            {{ $issuer['email'] }}<br>
            {{ $issuer['website'] }}
        </td>
        <td>
            @if ($issuer['directors'] !== '')
                <strong>Managing Directors</strong>
                {!! nl2br(e($issuer['directors'])) !!}
            @endif
            @if ($issuer['vat'] !== '')
                <br>VAT: {{ $issuer['vat'] }}
            @endif
        </td>
    </tr>
</table>
</body>
</html>
