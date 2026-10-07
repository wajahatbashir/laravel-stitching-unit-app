<?php

namespace App\Http\Controllers;

use App\Models\PeriodClosure;
use App\Support\Finance;
use App\Support\PeriodLock;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Admin → Month close: lock a finished month, or re-open it (with a reason). */
class PeriodCloseController extends Controller
{
    private function guard(): void
    {
        abort_unless(auth()->user()->can('period.close'), 403);
    }

    public function index()
    {
        $this->guard();
        $active = PeriodClosure::with('closer')->whereNull('reopened_at')->get()->keyBy(fn ($c) => $c->month->format('Y-m'));
        $months = collect(range(0, 23))->map(function ($i) use ($active) {
            $m = now()->startOfMonth()->subMonthsNoOverflow($i);
            $pl = Finance::profitLoss($m->toDateString(), $m->copy()->endOfMonth()->toDateString());

            return ['date' => $m, 'key' => $m->format('Y-m'), 'current' => $i === 0, 'closure' => $active[$m->format('Y-m')] ?? null,
                'revenue' => $pl['revenue'], 'costs' => $pl['costs'], 'profit' => $pl['net']];
        });

        return view('periods.index', [
            'months' => $months,
            'history' => PeriodClosure::with('closer', 'reopener')->latest('id')->limit(20)->get(),
        ]);
    }

    public function close(Request $r)
    {
        $this->guard();
        $d = $r->validate(['month' => ['required', 'date_format:Y-m'], 'note' => 'nullable|string|max:200']);
        $month = Carbon::createFromFormat('Y-m-d', $d['month'].'-01')->startOfMonth();
        if ($month->gte(now()->startOfMonth())) {
            throw ValidationException::withMessages(['month' => __('Only finished months can be closed.')]);
        }
        if (isset(PeriodLock::closedMonths()[$d['month']])) {
            throw ValidationException::withMessages(['month' => __('This month is already closed.')]);
        }
        PeriodClosure::create(['month' => $month->toDateString(), 'note' => $d['note'] ?? null]);
        PeriodLock::flush();

        return back()->with('success', __(':m is now closed.', ['m' => $month->translatedFormat('F Y')]));
    }

    public function reopen(Request $r, PeriodClosure $closure)
    {
        $this->guard();
        abort_if($closure->reopened_at, 404);
        $d = $r->validate(['reason' => 'required|string|min:5|max:200']);
        $closure->update(['reopened_at' => now(), 'reopened_by' => $r->user()->id, 'reopen_reason' => $d['reason']]);
        PeriodLock::flush();

        return back()->with('success', __(':m is open again.', ['m' => $closure->month->translatedFormat('F Y')]));
    }
}
