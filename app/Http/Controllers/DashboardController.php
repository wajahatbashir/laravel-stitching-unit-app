<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Worker;
use App\Support\DashboardPeriod;
use App\Support\Finance;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $r)
    {
        $u = auth()->user();
        $p = DashboardPeriod::resolve($r);
        $f = $p->from->toDateString();
        $t = $p->to->toDateString();
        $d = ['p' => $p];

        if ($u->can('orders.view')) {
            $d['orders'] = [
                'open' => Order::whereIn('status', ['pending', 'in_progress'])->count(),
                'pieces' => (int) Order::whereIn('status', ['pending', 'in_progress'])->sum('qty'),
                'due' => Order::whereIn('status', ['pending', 'in_progress'])->whereNotNull('due_date')->where('due_date', '<=', now()->addDays(7))->orderBy('due_date')->with('customer')->limit(5)->get(),
                'recent' => Order::with('customer')->latest('id')->limit(5)->get(),
                'late' => Order::whereIn('status', ['pending', 'in_progress'])->whereNotNull('due_date')->where('due_date', '<', now()->startOfDay())->orderBy('due_date')->with('customer')->limit(6)->get(),
            ];
            // how many open orders sit at each production stage (furthest stage reached)
            $pipe = array_fill_keys(array_merge(['new'], \App\Support\Production::keys()), 0);
            foreach (Order::whereIn('status', ['pending', 'in_progress'])->get() as $o) {
                $pipe[\App\Support\Production::summary($o)['current'] ?? 'new']++;
            }
            $d['pipeline'] = $pipe;
        }
        if ($u->can('expenses.view')) {
            $range = fn () => Expense::whereBetween('date', [$f, $t]);
            $d['expTotal'] = (float) $range()->sum('base_amount');
            $d['expByType'] = $range()->selectRaw('type, SUM(base_amount) s')->groupBy('type')->pluck('s', 'type');
            $d['recentExpenses'] = $range()->with('category')->latest('date')->latest('id')->limit(5)->get();
        }
        if ($u->can('reports.cash') || $u->hasRole(['Super Admin', 'Admin'])) {
            // "right now" figures
            $d['cb'] = Finance::cashBank();
            $d['recv'] = Finance::receivables();
            $d['vendorPay'] = Finance::vendorPayables();
            $d['custAdv'] = Finance::customerAdvances();
            [$d['payable'], $d['advances']] = Finance::workerBalances();

            // figures for the chosen period, compared with the equally long period before it
            [$pf, $pt] = $p->previous();
            $d['stats'] = Finance::periodStats($p->from, $p->to);
            $prev = Finance::periodStats($pf, $pt);
            $d['deltas'] = collect($d['stats'])->map(fn ($v, $k) => Finance::delta((float) $v, (float) $prev[$k]))->all();
            $d['prevLabel'] = $pf->translatedFormat('d M').' – '.$pt->translatedFormat('d M Y');

            $d['bucket'] = $p->bucket();
            $d['series'] = Finance::series($p->from, $p->chartTo(), $d['bucket']);
            $d['mix'] = Finance::costBreakdown($f, $t);
        }
        if ($u->can('backups.view')) {
            // warn when no backup (automatic or manual) exists from the last 36 hours
            $last = collect(\App\Services\BackupService::list())->first(fn ($b) => in_array($b['type'], ['nightly', 'monthly', 'manual']));
            $d['backupWarn'] = ! $last || \Illuminate\Support\Carbon::parse($last['created_at'])->lt(now()->subHours(36))
                ? ($last ? __('The last backup is from :t.', ['t' => \Illuminate\Support\Carbon::parse($last['created_at'])->diffForHumans()]) : __('No backup has been made yet.'))
                : null;
        }
        if ($u->can('inventory.view')) {
            $d['low'] = InventoryItem::where('is_active', true)->get()->filter(fn ($i) => $i->stock() <= (float) $i->min_stock && $i->min_stock > 0)->take(5);
        }
        if ($u->can('my.ledger') && ($w = Worker::where('user_id', $u->id)->first())) {
            $d['me'] = $w;
            $d['myBalance'] = $w->balance();
        }

        return view('dashboard', $d);
    }
}
