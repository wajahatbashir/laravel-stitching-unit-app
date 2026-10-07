@can('salaries.create')
<form method="POST" action="{{ route('salaries.generate') }}" class="flex items-center gap-1">
    @csrf
    <input type="month" name="month" value="{{ now()->format('Y-m') }}" class="input !min-h-9 !w-auto !py-1 text-xs">
    <button class="btn btn-gold btn-sm" title="{{ __('Create this month\'s salary entry for every active employee') }}">{{ __('Generate month') }}</button>
</form>
@endcan
