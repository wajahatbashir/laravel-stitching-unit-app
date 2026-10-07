<!DOCTYPE html>
<html><head><meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #222; }
    h1 { margin: 0; font-size: 22px; color: #0a0a0a; }
    .muted { color: #666; }
    table { width: 100%; border-collapse: collapse; margin-top: 14px; }
    th { background: #f3eee5; text-align: left; padding: 7px; font-size: 11px; }
    td { padding: 7px; border-bottom: 1px solid #e5e5e5; }
    .r { text-align: right; }
    .box { border: 1px solid #ccc; padding: 10px; }
    .sig td { border: 0; padding-top: 46px; text-align: center; font-size: 11px; }
    .sig span { display: block; border-top: 1px solid #333; padding-top: 4px; margin: 0 14px; }
</style></head>
<body>
<table style="margin:0"><tr>
    <td style="border:0;padding:0"><h1>{{ biz('business_name') }}</h1><div class="muted">{{ biz('business_phone') }}<br>{{ biz('business_address') }}</div></td>
    <td class="r" style="border:0;padding:0"><strong style="font-size:18px">DELIVERY CHALLAN</strong><br>No. <strong>{{ $d->challan_no }}</strong><br>Date: {{ fmt_date($d->date) }}</td>
</tr></table>

<div class="box" style="margin-top:16px">
    <strong>Deliver to:</strong> {{ $d->order->customer->name }}<br>
    @if ($d->order->customer->address)<span class="muted">{{ $d->order->customer->address }}</span><br>@endif
    <strong>Order:</strong> {{ $d->order->order_no }}@if ($d->order->collection_name) — {{ $d->order->collection_name }}@endif @if ($d->order->fabric_name) ({{ $d->order->fabric_name }})@endif
    @if ($d->vehicle)<br><strong>Vehicle / rider:</strong> {{ $d->vehicle }}@endif
</div>

<table>
    <tr><th>Description</th><th class="r">Pieces</th></tr>
    <tr><td>Stitched garments — {{ $d->order->collection_name ?: 'order '.$d->order->order_no }}</td><td class="r"><strong>{{ number_format($d->qty) }}</strong></td></tr>
</table>
<table style="width:55%;margin-left:45%">
    <tr><td>Order quantity</td><td class="r">{{ number_format($d->order->qty) }}</td></tr>
    <tr><td>Delivered earlier</td><td class="r">{{ number_format($before) }}</td></tr>
    <tr><td>This delivery</td><td class="r"><strong>{{ number_format($d->qty) }}</strong></td></tr>
    <tr><td>Balance to deliver</td><td class="r"><strong>{{ number_format(max(0, $d->order->qty - $before - $d->qty)) }}</strong></td></tr>
</table>
@if ($d->notes)<p><strong>Notes:</strong> {{ $d->notes }}</p>@endif

<table class="sig"><tr>
    <td><span>Delivered by</span></td>
    <td><span>Received by @if ($d->received_by)({{ $d->received_by }})@endif / stamp</span></td>
</tr></table>
<p class="muted" style="margin-top:30px;font-size:10px">Please check the quantity on receipt and sign the duplicate copy.</p>
</body></html>
