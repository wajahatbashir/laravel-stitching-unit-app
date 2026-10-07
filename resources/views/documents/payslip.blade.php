<!DOCTYPE html>
<html><head><meta charset="utf-8"></head>
<body>
@include('documents._head', ['title' => 'PAYSLIP', 'sub' => ($from || $to) ? fmt_date($from).' – '.fmt_date($to ?: now()) : 'All time to '.fmt_date(now())])

<div class="box" style="margin-top:16px">
    <strong>{{ $w->name }}</strong> — {{ label($w->type) }}, {{ label($w->pay_cycle) }}
    @if ($w->phone)<br><span class="muted">{{ $w->phone }}</span>@endif
    @if ($w->cnic)<br><span class="muted">CNIC: {{ $w->cnic }}</span>@endif
</div>

<table>
    <tr><th>Date</th><th>Details</th><th class="r">Earned</th><th class="r">Paid</th><th class="r">Balance</th></tr>
    @if ($opening != 0)<tr><td colspan="4"><em>Brought forward</em></td><td class="r">{{ number_format($opening, 2) }}</td></tr>@endif
    @foreach ($lines as $l)
        <tr><td>{{ fmt_date($l['date']) }}</td><td>{{ $l['desc'] }}</td>
            <td class="r">{{ $l['earned'] ? number_format($l['earned'], 2) : '' }}</td>
            <td class="r">{{ $l['paid'] ? number_format($l['paid'], 2) : '' }}</td>
            <td class="r">{{ number_format($l['balance'], 2) }}</td></tr>
    @endforeach
</table>

<table style="width:55%;margin-left:45%">
    <tr><td>Brought forward</td><td class="r">{{ number_format($opening, 2) }}</td></tr>
    <tr><td>Earned (net of deductions)</td><td class="r">{{ number_format($earned, 2) }}</td></tr>
    <tr><td>Paid / advances</td><td class="r">{{ number_format($paid, 2) }}</td></tr>
    <tr><td><strong>{{ $closing < 0 ? 'Advance to adjust' : 'Balance to pay' }}</strong></td><td class="r big">{{ money(abs($closing)) }}</td></tr>
</table>
<table class="sig"><tr><td><span>Prepared by</span></td><td><span>Received by {{ $w->name }}</span></td></tr></table>
</body></html>
