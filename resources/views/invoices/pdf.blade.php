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
    .tot tr.total td { border-top: 1px solid #222; }
    .paid { color: #047857; }
    .adj { color: #0369a1; }
    .due { color: #be123c; font-weight: bold; }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 8px; font-size: 10px; font-weight: bold; }
    .b-paid { background: #d1fae5; color: #047857; } .b-partial { background: #fef3c7; color: #92400e; } .b-unpaid { background: #ffe4e6; color: #be123c; }
</style></head>
<body>
<table style="margin:0"><tr>
    @if ($logo = brand_data_uri())<td style="border:0;padding:0;width:1%;vertical-align:middle"><img src="{{ $logo }}" alt="" style="display:block;max-height:60px;max-width:150px"></td>@endif
    <td style="border:0;padding:0 0 0 {{ $logo ? '12px' : '0' }};vertical-align:middle"><h1 style="line-height:1.1;margin-bottom:3px">{{ biz('business_name') }}</h1><div>{{ biz('business_phone') }}<br>{{ biz('business_address') }}</div></td>
    <td class="r" style="border:0;padding:0"><strong style="font-size:16px">INVOICE {{ $inv->number }}</strong><br>{{ fmt_date($inv->date) }}@if ($inv->due_date)<br>Due {{ fmt_date($inv->due_date) }}@endif<br><span class="badge b-{{ $inv->status() }}">{{ strtoupper(label($inv->status())) }}</span></td>
</tr></table>
<p><strong>Bill to:</strong> {{ $inv->customer->name }}@if ($inv->order)<br>Order: {{ $inv->order->order_no }}@endif</p>
<table>
    <tr><th>Description</th><th class="r">Qty</th><th class="r">Rate</th><th class="r">Amount</th></tr>
    @foreach ($inv->items as $i)
        <tr><td>{{ $i->description }}</td><td class="r">{{ (float) $i->qty }}</td><td class="r">{{ number_format($i->rate, 2) }}</td><td class="r">{{ number_format($i->amount, 2) }}</td></tr>
    @endforeach
</table>
<table class="tot" style="width:45%;margin-left:55%;margin-bottom:0">
    <tr><td>Subtotal</td><td class="r">{{ number_format($inv->subtotal, 2) }}</td></tr>
    @if ($inv->discount > 0)<tr><td>Discount</td><td class="r">-{{ number_format($inv->discount, 2) }}</td></tr>@endif
    @if ($inv->tax > 0)<tr><td>Tax</td><td class="r">{{ number_format($inv->tax, 2) }}</td></tr>@endif
    <tr class="total"><td><strong>Total</strong></td><td class="r"><strong>{{ money($inv->total) }}</strong></td></tr>
    <tr class="paid"><td>Paid</td><td class="r">{{ money($inv->paid()) }}</td></tr>
    @if ($inv->adjusted() > 0)<tr class="adj"><td>Adjustment</td><td class="r">{{ money($inv->adjusted()) }}</td></tr>@endif
    <tr class="due"><td>Balance</td><td class="r">{{ money($inv->balance()) }}</td></tr>
</table>
@if ($inv->notes)<div style="clear:both;margin-top:34px;padding-top:8px;border-top:1px solid #eee;line-height:1.5"><strong>Notes:</strong> {{ $inv->notes }}</div>@endif
</body></html>
