@extends('layouts.app')
@section('title', $title)
@section('content')
<form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="mx-auto max-w-3xl"
      @if ($offline) data-offline data-after="{{ route($route.'.index', [], false) }}" data-label="{{ $title }}" @endif>
    @csrf
    @if ($model) @method('PUT') @endif
    @if ($offline)<input type="hidden" name="uuid" value="{{ (string) \Illuminate\Support\Str::uuid() }}">@endif

    @if ($errors->any())
        <div class="mb-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-800 ring-1 ring-rose-200">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>
    @endif

    <div class="card p-4 md:p-6">
        <div class="grid gap-4 md:grid-cols-2">
            @foreach ($fields as $f)
                <x-field :f="$f" :model="$model" />
            @endforeach
        </div>
        @includeIf($route.'._extra', ['model' => $model, 'extra' => $extra])
        @include('crud._period_override', ['model' => $model])
    </div>

    <div class="sticky bottom-20 z-10 mt-4 flex gap-2 md:static">
        <button type="submit" class="btn btn-primary flex-1 md:flex-none">{{ __('Save') }}</button>
        <a href="{{ route($route.'.index') }}" class="btn btn-ghost">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection
