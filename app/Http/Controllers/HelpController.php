<?php

namespace App\Http\Controllers;

/** In-app help: purpose and basic how-to of every module the signed-in user can open. */
class HelpController extends Controller
{
    public function __invoke()
    {
        $u = auth()->user();
        $sections = collect(config('help'))->filter(function ($s) use ($u) {
            if ($s['route'] === 'my-ledger') {
                return $u->can('my.ledger') && $u->worker()->exists();
            }
            if ($s['route'] === 'reports.index') {
                return collect(\App\Support\Perms::REPORTS)->keys()->contains(fn ($k) => $u->can("reports.$k"));
            }

            return $s['perm'] === null || $u->can($s['perm']);
        });

        return view('help.index', ['groups' => $sections->groupBy('group', true)]);
    }
}
