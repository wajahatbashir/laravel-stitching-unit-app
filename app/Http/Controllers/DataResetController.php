<?php

namespace App\Http\Controllers;

use App\Services\DataReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/** Super Admin only: wipe the test/demo records so the business can start entering real data. */
class DataResetController extends Controller
{
    private function guard(Request $r): void
    {
        abort_unless($r->user()->hasRole('Super Admin'), 403);
    }

    public function index(Request $r)
    {
        $this->guard($r);

        return view('reset.index', ['counts' => DataReset::counts()]);
    }

    public function run(Request $r)
    {
        $this->guard($r);
        $r->validate([
            'confirm' => ['required', fn ($a, $v, $fail) => $v === 'RESET' || $fail(__('Type RESET (capital letters) to confirm.'))],
            'password' => ['required', fn ($a, $v, $fail) => Hash::check((string) $v, $r->user()->password) || $fail(__('Your password is not correct.'))],
        ]);

        set_time_limit(0);
        try {
            $backup = DataReset::run($r->user());
        } catch (\Throwable $e) {
            Log::error('Data reset failed: '.$e->getMessage());

            return back()->withErrors(['reset' => __('Reset failed — nothing was changed if the safety backup failed: :m', ['m' => $e->getMessage()])]);
        }

        return redirect()->route('dashboard')->with('success', __('All test data was cleared. A safety backup was saved as :b (Backups page) in case you need it back.', ['b' => $backup]));
    }
}
