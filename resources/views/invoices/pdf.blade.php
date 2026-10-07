<!DOCTYPE html>
<html><head><meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #222; }
    h1 { color: #0a0a0a; margin: 0; font-size: 22px; }
    table { width: 100%; border-collapse: collapse; margin-top: 14px; }
    th { background: #f3eee5; text-align: left; padding: 6px; font-size: 11px; }
    td { padding: 6px; border-bottom: 1px solid #eee; }
    .r { text-align: right; }
    .tot td { border: 0; padding: 3px 6px; }
</style></head>
<body>
<table style="margin:0"><tr>
    <td style="border:0;padding:0"><h1>{{ biz('business_name') }}</h1><div>{{ biz('business_phone') }}<br>{{ biz('business_address') }}</div></td>
    <td class="r" style="border:0;padding:0"><strong style="font-size:16px">INVOICE {{ $inv->number }}</strong><br>{{ fmt_date($inv->date) }}@if ($inv->due_date)<br>Due {{ fmt_date($inv->due_date) }}@endif</td>
</tr></table>
<p><strong>Bill to:</strong> {{ $inv->customer->name }}@if ($inv->order)<br>Order: {{ $inv->order->order_no }}@endif</p>
<table>
    <tr><th>Description</th><th class="r">Qty</th><th class="r">Rate</th><th class="r">Amount</th></tr>
    @foreach ($inv->items as $i)
        <tr><td>{{ $i->description }}</td><td class="r">{{ (float) $i->qty }}</td><td class="r">{{ number_format($i->rate, 2) }}</td><td class="r">{{ number_format($i->amount, 2) }}</td></tr>
    @endforeach
</table>
<table class="tot" style="width:45%;margin-left:55%">
    <tr><td>Subtotal</td><td class="r">{{ number_format($inv->subtotal, 2) }}</td></tr>
    @if ($inv->discount > 0)<tr><td>Discount</td><td class="r">-{{ number_format($inv->discount, 2) }}</td></tr>@endif
    @if ($inv->tax > 0)<tr><td>Tax</td><td class="r">{{ number_format($inv->tax, 2) }}</td></tr>@endif
    <tr><td><strong>Total</strong></td><td class="r"><strong>{{ money($inv->total) }}</strong></td></tr>
</table>
@if ($inv->notes)<p>{{ $inv->notes }}</p>@endif
</body></html>
