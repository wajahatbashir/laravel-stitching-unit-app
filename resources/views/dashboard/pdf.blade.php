<!DOCTYPE html>
<html><head><meta charset="utf-8">
<style>
    @page { margin: 26px 26px 34px 26px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1e293b; }
    h1 { margin: 0; font-size: 19px; color: #0a0a0a; }
    h2 { margin: 0 0 3px 0; font-size: 11px; color: #0f172a; }
    .muted { color: #64748b; }
    .tiny { font-size: 7.5px; color: #94a3b8; }
    .sec { margin: 12px 0 5px 0; font-size: 8.5px; font-weight: bold; letter-spacing: .6px; color: #64748b; text-transform: uppercase; }
    table { border-collapse: collapse; }
    .grid { width: 100%; table-layout: fixed; }
    .grid td { vertical-align: top; }
    .card { border: 1px solid #e2e8f0; border-radius: 6px; background: #ffffff; padding: 7px 9px; }
    .num { font-size: 13px; font-weight: bold; color: #0f172a; }
    .good { color: #047857; } .bad { color: #be123c; } .warn { color: #b45309; }
    .r { text-align: right; }
    .bar { height: 7px; background: #2a78d6; }
    .track { background: #f1f5f9; }
    .row td { border-top: 1px solid #e2e8f0; padding: 3px 0; }
    .chip { background: #f1f5f9; color: #94a3b8; padding: 1px 4px; font-size: 7.5px; }
    .chip-on { background: #ede4d3; color: #5c4513; font-weight: bold; }
    .late { background: #fff1f2; border: 1px solid #fecdd3; border-radius: 5px; padding: 5px 7px; }
    .footer { position: fixed; bottom: -22px; left: 0; right: 0; font-size: 7px; color: #94a3b8; text-align: center; }
</style></head>
<body>
@php
    $user = auth()->user();
    $tz = 'Pakistan time';
    // gold + blue: the same validated series colours as the dashboard charts
    $REV = '#b8893a'; $COST = '#2a78d6';
@endphp

{{-- header --}}
<table class="grid"><tr>
    <td style="width:62%"><h1>{{ biz('business_name', 'Lumiere Premium') }}</h1><div class="muted" style="font-size:10px">{{ __('Dashboard report') }} · {{ $p->label() }}</div></td>
    <td class="r muted" style="width:38%;font-size:8px">{{ now()->format('l, d F Y · H:i') }} ({{ $tz }})<br>{{ __('Prepared by') }} {{ $user->name }}</td>
</tr></table>
<div style="border-top:2px solid #b8893a;margin-top:5px"></div>

{{-- my balance (worker with a login) --}}
@isset($me)
    <div class="card" style="margin-top:8px;background:#0a0a0a;color:#ffffff"><span style="color:#d6c9ad">{{ __('My balance') }} — {{ $me->name }}</span><br><span style="font-size:15px;font-weight:bold">{{ money(abs($myBalance)) }}</span> <span style="color:#d6c9ad">{{ $myBalance > 0 ? __('Payable to me') : ($myBalance < 0 ? __('Advance to adjust') : __('All settled')) }}</span></div>
@endisset

{{-- money position (today) --}}
@isset($cb)
    @php
        $cash = $cb['cash']['balance']; $bank = $cb['bank']['balance']; $available = round($cash + $bank, 2);
        $split = abs($cash) > 0.004 ? __('bank').' '.money($bank).' · '.__('cash').' '.money($cash) : null;
        $money = [
            [__('Money available'), money($available), $available < 0 ? 'bad' : '', $split],
            [__('Receivable'), money($recv), 'good', __('from customers').($custAdv > 0 ? ' · '.__('advances held').' '.money($custAdv) : '').(($overdue ?? 0) > 0 ? ' · '.__('past due').' '.money($overdue) : '').(($overpaid ?? 0) > 0 ? ' · '.__('overpaid').' '.money($overpaid) : '')],
            [__('Payable'), money($payable + max(0, $vendorPay)), 'bad', __('workers').' '.money($payable).($vendorPay > 0 ? ' · '.__('vendors').' '.money($vendorPay) : '')],
        ];
    @endphp
    <div class="sec">{{ __('Money position') }} · {{ __('today') }}</div>
    <table class="grid"><tr>
        @foreach ($money as $i => [$l, $v, $tone, $sub])
            <td style="width:33.33%;padding:0 {{ $i < 2 ? '5px' : '0' }} 0 0"><div class="card"><div class="muted">{{ $l }}</div><div class="num {{ $tone }}">{{ $v }}</div>@if ($sub)<div class="tiny">{{ $sub }}</div>@endif</div></td>
        @endforeach
    </tr></table>

    {{-- selected period badges, same order as the dashboard --}}
    @php
        $tiles = [
            ['revenue', __('Revenue (invoiced)'), 'up', null],
            ['received', __('Payments received'), 'up', null],
            ['orders', __('Orders received'), 'up', null],
            ['labour_earned', __('Labour earned'), 'none', __('work + salaries · cost when paid')],
            ['expense_costs', __('Expenses'), 'down', __('entries on the Expenses page')],
            ['wages', __('Wages paid'), 'none', __('wages, salaries & advances paid')],
            ['costs', __('Total costs'), 'down', __('expenses + wages paid')],
            ['profit', $stats['profit'] >= 0 ? __('Net profit') : __('Net loss'), 'up', null],
        ];
    @endphp
    <div class="sec">{{ __('Selected period') }} · {{ $p->label() }}</div>
    <table class="grid">
        @foreach (array_chunk($tiles, 4) as $row)
            <tr>
                @foreach ($row as $j => [$k, $label, $good, $hint])
                    @php
                        $v = $stats[$k]; $dl = $deltas[$k] ?? null;
                        $tone = $k === 'profit' ? ($v >= 0 ? 'good' : 'bad') : '';
                        $better = $dl === null || $good === 'none' ? null : (($dl >= 0) === ($good === 'up'));
                    @endphp
                    <td style="width:25%;padding:0 {{ $j < 3 ? '5px' : '0' }} 5px 0"><div class="card">
                        <div class="muted">{{ $label }}</div>
                        <div class="num {{ $tone }}">{{ $k === 'orders' ? number_format($v) : money($v) }}</div>
                        @if ($k === 'orders')<div class="tiny">{{ number_format($stats['pieces']) }} {{ __('pieces') }}</div>@elseif ($hint)<div class="tiny">{{ $hint }}</div>@endif
                        @if ($k === 'wages' && ($stats['prev_due_paid'] ?? 0) > 0)<div class="tiny warn">{{ __('of which') }} {{ money($stats['prev_due_paid']) }} {{ __('paid on old dues') }}</div>@endif
                        @if ($dl !== null)<div class="tiny {{ $better === null ? '' : ($better ? 'good' : 'bad') }}">{{ $dl > 0 ? '▲' : ($dl < 0 ? '▼' : '■') }} {{ abs($dl) }}% {{ __('vs previous') }}</div>@endif
                    </div></td>
                @endforeach
            </tr>
        @endforeach
    </table>
@endisset

{{-- charts --}}
@isset($series)
    @php
        $n = count($series);
        $max = nice_max((float) max(1, collect($series)->max(fn ($m) => max($m['revenue'], $m['costs']))));
        $hasData = collect($series)->contains(fn ($m) => $m['revenue'] > 0 || $m['costs'] > 0);
        $H = 105;                                   // chart height in points
        $mixTotal = collect($mix)->sum(1);
        $mixMax = (float) (collect($mix)->max(1) ?: 1);
        $w = $n ? floor(100 / $n * 10) / 10 : 100;
    @endphp
    <div class="sec">{{ __('Charts') }}</div>
    <table class="grid"><tr>
        <td style="width:57%;padding-right:6px"><div class="card">
            <h2>{{ __('Revenue vs costs') }}</h2>
            <div class="tiny">{{ $p->label() }} · {{ __('per') }} {{ __($bucket) }} · {{ __('invoiced revenue against all costs') }}</div>
            <div style="margin:3px 0 2px 0;font-size:8px"><span style="color:{{ $REV }}">■</span> {{ __('Revenue') }} &nbsp; <span style="color:{{ $COST }}">■</span> {{ __('Costs') }} &nbsp; <span class="tiny">{{ __('top of chart') }} = {{ money($max) }}</span></div>
            @if (! $hasData)
                <div class="muted" style="padding:28px 0;text-align:center;background:#f8fafc">{{ __('No invoices or costs in this period yet.') }}</div>
            @else
                <table style="width:100%;table-layout:fixed;border-bottom:1px solid #cbd5e1"><tr>
                    @foreach ($series as $m)
                        @php $hr = $m['revenue'] > 0 ? max(2, round($m['revenue'] / $max * $H)) : 0; $hc = $m['costs'] > 0 ? max(2, round($m['costs'] / $max * $H)) : 0; @endphp
                        <td valign="top" style="padding:0 1px">
                            <table style="width:100%;table-layout:fixed"><tr>
                                {{-- a spacer pushes each bar down onto the baseline (vertical-align is unreliable in PDFs) --}}
                                <td valign="top" style="padding:0 .5px"><div style="height:{{ $H - $hr }}px;font-size:1px;line-height:1px">&nbsp;</div>@if ($hr)<div style="height:{{ $hr }}px;background:{{ $REV }};font-size:1px;line-height:1px">&nbsp;</div>@endif</td>
                                <td valign="top" style="padding:0 .5px"><div style="height:{{ $H - $hc }}px;font-size:1px;line-height:1px">&nbsp;</div>@if ($hc)<div style="height:{{ $hc }}px;background:{{ $COST }};font-size:1px;line-height:1px">&nbsp;</div>@endif</td>
                            </tr></table>
                        </td>
                    @endforeach
                </tr></table>
                <table style="width:100%;table-layout:fixed"><tr>@foreach ($series as $m)<td style="text-align:center;font-size:{{ $n > 16 ? 5 : 7 }}px;color:#64748b;padding:1px 0">{{ $m['label'] }}</td>@endforeach</tr></table>
                @if ($n <= 12)
                    <table style="width:100%;margin-top:4px;font-size:7.5px">
                        <tr class="muted"><td></td><td class="r">{{ __('Revenue') }}</td><td class="r">{{ __('Costs') }}</td><td class="r">{{ __('Profit') }}</td></tr>
                        @foreach ($series as $m)
                            <tr class="row"><td>{{ $m['full'] }}</td><td class="r">{{ money($m['revenue']) }}</td><td class="r">{{ money($m['costs']) }}</td><td class="r {{ $m['profit'] >= 0 ? 'good' : 'bad' }}">{{ money($m['profit']) }}</td></tr>
                        @endforeach
                    </table>
                @endif
            @endif
        </div></td>
        <td style="width:43%"><div class="card">
            <h2>{{ __('Where the money went') }}</h2>
            <div class="tiny">{{ $p->label() }} · {{ __('total') }} {{ money($mixTotal) }}</div>
            @if (! $mixTotal)
                <div class="muted" style="padding:28px 0;text-align:center;background:#f8fafc;margin-top:6px">{{ __('No costs recorded in this period.') }}</div>
            @else
                <table style="width:100%;margin-top:5px">
                    @foreach ($mix as [$label, $value])
                        <tr><td style="width:38%;padding:3px 4px 3px 0;font-size:8px">{{ $label }}</td>
                            <td class="track" style="width:42%;padding:0"><div class="bar" style="width:{{ max(2, round($value / $mixMax * 100)) }}%">&nbsp;</div></td>
                            <td class="r" style="width:20%;padding:3px 0 3px 4px;font-size:8px">{{ compact_num($value) }}</td></tr>
                    @endforeach
                </table>
                <div class="tiny" style="margin-top:4px">{{ __('Wages and salaries are counted on the day they are paid.') }}</div>
            @endif
        </div></td>
    </tr></table>
@endisset

{{-- orders / expenses / low stock --}}
<table class="grid" style="margin-top:8px"><tr>
    @isset($orders)
        <td style="width:50%;padding-right:6px"><div class="card">
            <h2>{{ __('Orders') }}</h2>
            <table class="grid" style="margin:3px 0 4px 0"><tr>
                <td style="width:50%;padding-right:3px"><div style="background:#f8f4ea;padding:4px;text-align:center"><span style="font-size:14px;font-weight:bold">{{ $orders['open'] }}</span><br><span class="tiny">{{ __('Open orders') }}</span></div></td>
                <td style="width:50%;padding-left:3px"><div style="background:#f8f4ea;padding:4px;text-align:center"><span style="font-size:14px;font-weight:bold">{{ number_format($orders['pieces']) }}</span><br><span class="tiny">{{ __('Pieces in progress') }}</span></div></td>
            </tr></table>
            @isset($pipeline)
                <div style="margin-bottom:4px">
                    @foreach ($pipeline as $k => $cnt)<span class="chip {{ $cnt ? 'chip-on' : '' }}">{{ $k === 'new' ? __('Not started') : \App\Support\Production::label($k) }} · {{ $cnt }}</span> @endforeach
                </div>
            @endisset
            @if ($orders['late']->isNotEmpty())
                <div class="late"><div class="bad" style="font-weight:bold;font-size:7.5px">⚠ {{ __('Overdue orders') }} ({{ $orders['late']->count() }})</div>
                    @foreach ($orders['late'] as $o)<table style="width:100%"><tr><td>{{ $o->order_no }} · {{ $o->customer->name }}</td><td class="r bad">{{ (int) $o->due_date->diffInDays(now()->startOfDay()) }} {{ __('days late') }}</td></tr></table>@endforeach
                </div>
            @endif
            @if ($orders['due']->isNotEmpty())
                <div class="tiny bad" style="margin-top:4px;text-transform:uppercase">{{ __('Due within 7 days') }}</div>
                <table style="width:100%">@foreach ($orders['due'] as $o)<tr class="row"><td>{{ $o->order_no }} · {{ $o->customer->name }}</td><td class="r bad">{{ fmt_date($o->due_date) }}</td></tr>@endforeach</table>
            @endif
            <div class="tiny" style="margin-top:4px;text-transform:uppercase">{{ __('Recent') }}</div>
            <table style="width:100%">@foreach ($orders['recent'] as $o)<tr class="row"><td>{{ $o->order_no }} · {{ $o->customer->name }}</td><td class="r muted">{{ label($o->status) }}</td></tr>@endforeach</table>
        </div></td>
    @endisset
    @isset($expTotal)
        <td style="width:50%"><div class="card">
            <h2>{{ __('Expenses') }} · {{ $p->label() }}</h2>
            <div class="num" style="margin:2px 0 4px 0">{{ money($expTotal) }}</div>
            <table style="width:100%">
                @foreach (['order', 'unit', 'labour', 'other'] as $t)
                    @php $v = (float) ($expByType[$t] ?? 0); $pct = $expTotal > 0 ? round($v / $expTotal * 100) : 0; @endphp
                    <tr><td style="width:30%;padding:2px 4px 2px 0">{{ label($t) }}</td>
                        <td class="track" style="width:44%;padding:0"><div class="bar" style="width:{{ $pct }}%;background:#1f2937">&nbsp;</div></td>
                        <td class="r" style="width:26%;padding:2px 0 2px 4px">{{ money($v) }}</td></tr>
                @endforeach
            </table>
            <div class="tiny" style="margin-top:5px;text-transform:uppercase">{{ __('Recent') }}</div>
            <table style="width:100%">@foreach ($recentExpenses as $e)<tr class="row"><td>{{ \Illuminate\Support\Str::limit($e->title ?: $e->category?->name, 30) }}</td><td class="r">{{ money($e->base_amount) }}</td></tr>@endforeach</table>
        </div></td>
    @endisset
</tr></table>

@if (isset($low) && $low->isNotEmpty())
    <div class="card" style="margin-top:8px"><h2 class="bad">{{ __('Low stock') }}</h2>
        <table style="width:100%">@foreach ($low as $i)<tr class="row"><td>{{ $i->name }}</td><td class="r">{{ $i->stock() }} {{ $i->unit }}</td></tr>@endforeach</table></div>
@endif

<div class="footer">{{ biz('business_name', 'Lumiere Premium') }} · {{ __('Dashboard report') }} · {{ $p->label() }} · {{ now()->format('d M Y, H:i') }}</div>
</body></html>
