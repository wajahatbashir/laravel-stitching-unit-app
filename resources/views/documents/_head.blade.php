<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #222; }
    h1 { margin: 0; font-size: 22px; color: #0a0a0a; }
    .muted { color: #666; }
    table { width: 100%; border-collapse: collapse; margin-top: 14px; }
    th { background: #f3eee5; text-align: left; padding: 7px; font-size: 11px; }
    td { padding: 6px 7px; border-bottom: 1px solid #e5e5e5; vertical-align: top; }
    .r { text-align: right; }
    .box { border: 1px solid #ccc; padding: 10px; }
    .big { font-size: 20px; font-weight: bold; }
    .sig td { border: 0; padding-top: 46px; text-align: center; font-size: 11px; }
    .sig span { display: block; border-top: 1px solid #333; padding-top: 4px; margin: 0 14px; }
</style>
<table style="margin:0"><tr>
    <td style="border:0;padding:0"><h1>{{ biz('business_name') }}</h1><div class="muted">{{ biz('business_phone') }}<br>{{ biz('business_address') }}</div></td>
    <td class="r" style="border:0;padding:0"><strong style="font-size:18px">{{ $title }}</strong><br>{{ $sub ?? '' }}</td>
</tr></table>
