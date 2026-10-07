<!DOCTYPE html>
<html><head><meta charset="utf-8"></head>
<body>
@include('documents._head', ['title' => 'ACCOUNT STATEMENT', 'sub' => (($from || $to) ? fmt_date($from).' – '.fmt_date($to ?: now()) : 'All time to '.fmt_date(now()))])

<div class="box" style="margin-top:16px">
    <strong>{{ $c->name }}</strong>@if ($c->contact_person) — {{ $c->contact_person }}@endif
    @if ($c->address)<br><span class="muted">{{ $c->address }}</span>@endif
</div>

<table>
    <tr><th>Date</th><th>Details</th><th class="r">Billed</th><th class="r">Received</th><th class="r">Balance</th></tr>
    @if ($opening != 0)<tr><td colspan="4"><em>Brought forward</em></td><td class="r">{{ number_format($opening, 2) }}</td></tr>@endif
    @forelse ($lines as $l)
        <tr><td>{{ fmt_date($l['date']) }}</td><td>{{ $l['desc'] }}</td>
            <td class="r">{{ $l['billed'] ? number_format($l['billed'], 2) : '' }}</td>
            <td class="r">{{ $l['received'] ? number_format($l['received'], 2) : '' }}</td>
            <td class="r">{{ number_format($l['balance'], 2) }}</td></tr>
    @empty
        <tr><td colspan="5" class="muted">No transactions in this period.</td></tr>
    @endforelse
</table>

<table style="width:55%;margin-left:45%">
    <tr><td><strong>Balance at {{ fmt_date($to ?: now()) }}</strong></td><td class="r big">{{ money($closing) }}</td></tr>
    @if ($advance > 0)<tr><td>Unused advance held for you</td><td class="r">{{ money($advance) }}</td></tr>@endif
</table>
<p class="muted" style="margin-top:24px;font-size:10px">Positive balance = amount due to {{ biz('business_name') }}. Please contact us if anything looks incorrect.</p>
</body></html>
