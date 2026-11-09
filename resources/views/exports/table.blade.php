<!DOCTYPE html>
<html><head><meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #222; }
    h1 { color: #0a0a0a; font-size: 16px; margin: 0 0 2px; }
    .sub { color: #777; margin-bottom: 10px; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #f3eee5; text-align: left; padding: 5px; font-size: 9px; }
    td { padding: 4px 5px; border-bottom: 1px solid #eee; }
    .tot { margin-top: 12px; width: auto; }
    .tot td { border: 0; padding: 2px 10px 2px 0; font-weight: bold; }
    .tot td.v { text-align: right; }
    .tot tr.last td { border-top: 1px solid #222; padding-top: 4px; }
</style></head>
<body>
<h1>{{ biz('business_name') }} — {{ $title }}</h1>
<div class="sub">Generated {{ now()->format('d M Y H:i') }}</div>
<table>
    <tr>@foreach ($head as $h)<th>{{ $h }}</th>@endforeach</tr>
    @foreach ($rows as $r)<tr>@foreach ($r as $c)<td>{{ is_numeric($c) && ! is_int($c) ? number_format($c, 2) : $c }}</td>@endforeach</tr>@endforeach
</table>
@if ($totals)
    <table class="tot">@foreach ($totals as $k => $v)<tr @if ($loop->last && $loop->count > 1) class="last" @endif><td>{{ $k }}</td><td class="v">{{ is_numeric($v) ? number_format($v, 2) : $v }}</td></tr>@endforeach</table>
@endif
</body></html>
