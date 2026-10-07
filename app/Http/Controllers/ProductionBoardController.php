<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Support\Production;
use Illuminate\Http\Request;

/** Board: every open order in the column of the furthest stage it has reached. */
class ProductionBoardController extends Controller
{
    public function index(Request $r)
    {
        abort_unless($r->user()->can('production.view') || $r->user()->can('orders.view'), 403);

        $orders = Order::with('customer')
            ->whereNotIn('status', $r->boolean('all') ? ['cancelled'] : ['cancelled', 'delivered'])
            ->when($r->customer_id, fn ($q, $v) => $q->where('customer_id', $v))
            ->orderBy('due_date')->orderBy('id')->get();

        $columns = ['new' => __('Not started')] + Production::options();
        $cards = array_fill_keys(array_keys($columns), []);
        foreach ($orders as $o) {
            $s = Production::summary($o);
            $late = $o->due_date && $o->due_date->lt(now()->startOfDay()) && $s['delivered'] < $o->qty && ! in_array($o->status, ['delivered', 'cancelled']);
            $cards[$s['current'] ?? 'new'][] = ['order' => $o, 's' => $s, 'late' => $late,
                'days' => $o->due_date ? (int) now()->startOfDay()->diffInDays($o->due_date, false) : null];
        }

        return view('production.board', [
            'columns' => $columns,
            'cards' => $cards,
            'total' => $orders->count(),
            'late' => collect($cards)->flatten(1)->where('late', true)->count(),
            'customers' => opts(Customer::class),
        ]);
    }
}
