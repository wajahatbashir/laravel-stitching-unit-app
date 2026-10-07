<?php

namespace App\Http\Controllers;

use App\Services\BackupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/** Admin → Backups: back up now, download, upload, delete, and (owner only) restore. */
class BackupController extends Controller
{
    private function view(): void
    {
        abort_unless(auth()->user()->can('backups.view'), 403);
    }

    public function index()
    {
        $this->view();
        $list = BackupService::list();
        $auto = collect($list)->first(fn ($b) => in_array($b['type'], ['nightly', 'monthly']));

        return view('backups.index', [
            'backups' => $list,
            'lastAuto' => $auto,
            'totalSize' => array_sum(array_column($list, 'size')),
            'canRestore' => auth()->user()->can('backups.restore'),
            'offsite' => \App\Services\OffsiteBackup::status(),
        ]);
    }

    public function store(Request $r)
    {
        $this->view();
        set_time_limit(0);
        try {
            $m = BackupService::create('manual', auth()->user()->name);
        } catch (\Throwable $e) {
            Log::error('Manual backup failed: '.$e->getMessage());

            return back()->withErrors(['backup' => __('Backup failed: :m', ['m' => $e->getMessage()])]);
        }

        $msg = __('Backup created: :n (:s)', ['n' => $m['name'], 's' => number_format($m['size'] / 1048576, 2).' MB']);
        if (\App\Services\OffsiteBackup::mode()) {
            $o = \App\Services\OffsiteBackup::push($m['name']);
            if (! $o['ok']) {
                return back()->with('success', $msg)->withErrors(['backup' => __('Off-site copy failed: :m', ['m' => $o['error']])]);
            }
            $msg .= ' · '.__('copied off-site');
        }

        return back()->with('success', $msg);
    }

    /** Copy the newest backup to the off-site destination right now. */
    public function offsite()
    {
        $this->view();
        set_time_limit(0);
        $latest = BackupService::list()[0] ?? null;
        if (! $latest) {
            return back()->withErrors(['backup' => __('There is no backup yet — press Back up now first.')]);
        }
        $r = \App\Services\OffsiteBackup::push($latest['name']);

        return $r['ok']
            ? back()->with('success', __('Copied to off-site: :n', ['n' => $latest['name']]))
            : back()->withErrors(['backup' => __('Off-site copy failed: :m', ['m' => $r['error']])]);
    }

    public function download(string $name)
    {
        $this->view();

        return response()->download(BackupService::path($name), $name);
    }

    public function destroy(string $name)
    {
        $this->view();
        BackupService::delete($name);

        return back()->with('success', __('Backup deleted.'));
    }

    public function upload(Request $r)
    {
        $this->view();
        $r->validate(['backup' => 'required|file|mimes:zip|max:1048576']); // up to 1 GB
        try {
            BackupService::import($r->file('backup'));
        } catch (\Throwable $e) {
            return back()->withErrors(['backup' => $e->getMessage()]);
        }

        return back()->with('success', __('Backup uploaded. You can now restore it.'));
    }

    public function restoreForm(string $name)
    {
        abort_unless(auth()->user()->can('backups.restore'), 403);
        $meta = collect(BackupService::list())->firstWhere('name', $name);
        abort_unless($meta, 404);

        return view('backups.restore', ['b' => $meta]);
    }

    public function restore(Request $r, string $name)
    {
        abort_unless($r->user()->can('backups.restore'), 403);
        BackupService::path($name); // 404 for unknown / malformed names
        $r->validate([
            'confirm' => ['required', fn ($a, $v, $fail) => $v === 'RESTORE' || $fail(__('Type RESTORE (capital letters) to confirm.'))],
            'password' => ['required', fn ($a, $v, $fail) => Hash::check((string) $v, $r->user()->password) || $fail(__('Your password is not correct.'))],
        ]);

        ignore_user_abort(true);
        set_time_limit(0);
        try {
            $res = BackupService::restore($name, $r->user()->name);
        } catch (\Throwable $e) {
            Log::error('Restore failed: '.$e->getMessage());

            return back()->withErrors(['restore' => __('Restore failed: :m', ['m' => $e->getMessage()])]);
        }
        // the sessions table was replaced by the backup's copy → everybody signs in again
        auth()->logout();

        return redirect()->route('login', ['restored' => $res['safety_backup']]);
    }
}
