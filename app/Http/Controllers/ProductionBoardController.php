<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Support\Production;
use Illuminate\Http\Request;

/**
 * Board: every open order in the column of the furthest stage it has reached, then two closing columns —
 * Completed (order status "completed", ready to hand over) and Delivered (status "delivered", i.e. fully delivered).
 * Delivered orders from the last 30 days are shown; "Show all delivered orders" lists older ones too.
 */
class ProductionBoardController extends Controller
{
    public const RECENT_DAYS = 30;

    public function index(Request $r)
    {
        abort_unless($r->user()->can('production.view') || $r->user()->can('orders.view'), 403);

        $cutoff = now()->subDays(self::RECENT_DAYS)->toDateString();
        $orders = Order::with('customer', 'brand')
            ->where('status', '!=', 'cancelled')
            ->where(fn ($q) => $q->where('status', '!=', 'delivered')->when(
                $r->boolean('all'),
                fn ($x) => $x->orWhere('status', 'delivered'),
                // delivered recently = last delivery date (else last update) within the window
                fn ($x) => $x->orWhere(fn ($w) => $w->where('status', 'delivered')
                    ->whereRaw('COALESCE((SELECT MAX(d.date) FROM deliveries d WHERE d.order_id = orders.id), DATE(orders.updated_at)) >= ?', [$cutoff]))
            ))
            ->when($r->customer_id, fn ($q, $v) => $q->where('customer_id', $v))
            ->orderBy('due_date')->orderBy('id')->get();

        $columns = ['new' => __('Not started')] + Production::options() + ['completed' => __('Completed'), 'delivered' => __('Delivered')];
        $cards = array_fill_keys(array_keys($columns), []);
        foreach ($orders as $o) {
            $s = Production::summary($o);
            $late = $o->due_date && $o->due_date->lt(now()->startOfDay()) && $s['delivered'] < $o->qty && ! in_array($o->status, ['delivered', 'cancelled']);
            $col = match ($o->status) {
                'delivered' => 'delivered',
                'completed' => 'completed',
                default => $s['current'] ?? 'new',
            };
            $cards[$col][] = ['order' => $o, 's' => $s, 'late' => $late,
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
