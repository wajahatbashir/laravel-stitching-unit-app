<!DOCTYPE html>
<html><head><meta charset="utf-8"></head>
<body>
@include('documents._head', ['title' => 'PAYMENT VOUCHER', 'sub' => 'No. PV-'.str_pad($p->id, 5, '0', STR_PAD_LEFT).' · '.fmt_date($p->date)])

<div class="box" style="margin-top:16px">
    <strong>{{ $p->type === 'advance' ? 'Advance paid to' : 'Paid to' }}:</strong> {{ $p->worker->name }}@if ($p->worker->phone) <span class="muted">({{ $p->worker->phone }})</span>@endif<br>
    <strong>Via:</strong> {{ label($p->mode) }}
    @if ($p->period_from || $p->period_to)<br><strong>For period:</strong> {{ fmt_date($p->period_from) }} – {{ fmt_date($p->period_to) }}@endif
    @if ($p->notes)<br><strong>Notes:</strong> {{ $p->notes }}@endif
</div>

<table>
    <tr><th>Description</th><th class="r">Amount</th></tr>
    <tr><td>{{ $p->type === 'advance' ? 'Advance' : 'Wages / salary payment' }}</td><td class="r big">{{ money($p->amount) }}</td></tr>
</table>
<table style="width:55%;margin-left:45%">
    <tr><td>{{ $before < 0 ? 'Advance held before' : 'Owed before this payment' }}</td><td class="r">{{ number_format(abs($before), 2) }}</td></tr>
    <tr><td>This payment</td><td class="r">{{ number_format($p->amount, 2) }}</td></tr>
    <tr><td><strong>{{ $after < 0 ? 'Advance to adjust' : 'Balance remaining' }}</strong></td><td class="r"><strong>{{ number_format(abs($after), 2) }}</strong></td></tr>
</table>
<table class="sig"><tr><td><span>Paid by</span></td><td><span>Received by {{ $p->worker->name }}</span></td></tr></table>
</body></html>
