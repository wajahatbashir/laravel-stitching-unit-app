@extends('layouts.app')
@section('title', __('Payment voucher'))
@section('actions')
    @can('worker_payments.edit')<a href="{{ route('worker-payments.edit', $p) }}" class="btn btn-ghost btn-sm"><x-icon name="pencil" class="h-4 w-4" />{{ __('Edit') }}</a>@endcan
@endsection
@section('content')
<div class="mx-auto max-w-2xl space-y-4">
    <div class="card p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div><p class="text-xl font-bold">PV-{{ str_pad($p->id, 5, '0', STR_PAD_LEFT) }}</p><p class="text-sm text-slate-500">{{ fmt_date($p->date) }} · {{ label($p->type) }} · {{ label($p->mode) }}</p></div>
            <p class="text-3xl font-bold tabular-nums">{{ money($p->amount) }}</p>
        </div>
        <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
            <div><dt class="text-xs text-slate-400">{{ __('Worker') }}</dt><dd><a class="text-brand-700 underline" href="{{ route('workers.show', $p->worker_id) }}">{{ $p->worker->name }}</a></dd></div>
            <div><dt class="text-xs text-slate-400">{{ __('Period') }}</dt><dd>{{ $p->period_from || $p->period_to ? fmt_date($p->period_from).' – '.fmt_date($p->period_to) : '—' }}</dd></div>
        </dl>
        @if ($p->notes)<p class="mt-4 rounded-xl bg-slate-50 p-3 text-sm text-slate-600">{{ $p->notes }}</p>@endif
        <div class="mt-4 border-t pt-3">
            @include('documents._share', ['kind' => 'voucher', 'id' => $p->id, 'phone' => $p->worker->phone,
                'text' => __(':b — payment voucher PV-:n: :a paid to :w on :d.', ['b' => biz('business_name'), 'n' => str_pad($p->id, 5, '0', STR_PAD_LEFT), 'a' => money($p->amount), 'w' => $p->worker->name, 'd' => fmt_date($p->date)])])
        </div>
    </div>
</div>
@endsection
