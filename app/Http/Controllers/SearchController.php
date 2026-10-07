<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Vendor;
use App\Models\Worker;
use Illuminate\Http\Request;

/** Header search: finds records across modules the user may view (row scoping applies automatically). */
class SearchController extends Controller
{
    public function __invoke(Request $r)
    {
        $q = trim((string) $r->query('q'));
        if (mb_strlen($q) < 2) {
            return response()->json(['groups' => []]);
        }
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
        $u = $r->user();
        $groups = [];

        $add = function (string $title, string $perm, $query, \Closure $map) use (&$groups, $u) {
            if (! $u->can($perm)) {
                return;
            }
            $items = $query->limit(5)->get()->map($map)->values();
            if ($items->isNotEmpty()) {
                $groups[] = ['title' => __($title), 'items' => $items];
            }
        };

        $add('Orders', 'orders.view',
            Order::with('customer')->where(fn ($w) => $w->where('order_no', 'like', $like)->orWhere('collection_name', 'like', $like)
                ->orWhere('fabric_name', 'like', $like)->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like)))->latest('id'),
            fn ($o) => ['label' => $o->order_no, 'sub' => trim($o->customer->name.' · '.($o->collection_name ?: '')), 'url' => route('orders.show', $o, false)]);

        $add('Customers', 'customers.view',
            Customer::where(fn ($w) => $w->where('name', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('contact_person', 'like', $like)),
            fn ($c) => ['label' => $c->name, 'sub' => $c->phone ?: __('Customer'), 'url' => route('customers.show', $c, false)]);

        $add('Invoices', 'invoices.view',
            Invoice::with('customer')->where(fn ($w) => $w->where('number', 'like', $like)->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like)))->latest('id'),
            fn ($i) => ['label' => $i->number, 'sub' => $i->customer->name.' · '.money($i->total), 'url' => route('invoices.show', $i, false)]);

        $add('Workers', 'workers.view',
            Worker::where(fn ($w) => $w->where('name', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('cnic', 'like', $like)),
            fn ($w) => ['label' => $w->name, 'sub' => label($w->type).' · '.label($w->pay_cycle), 'url' => route('workers.show', $w, false)]);

        $add('Expenses', 'expenses.view',
            Expense::with('category')->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('payee', 'like', $like)->orWhere('notes', 'like', $like))->latest('id'),
            fn ($e) => ['label' => $e->title ?: ($e->payee ?: label($e->type)), 'sub' => fmt_date($e->date).' · '.money($e->base_amount), 'url' => route('expenses.show', $e, false)]);

        $add('Vendors', 'vendors.view',
            Vendor::where(fn ($w) => $w->where('name', 'like', $like)->orWhere('phone', 'like', $like)),
            fn ($v) => ['label' => $v->name, 'sub' => $v->phone ?: __('Vendor'), 'url' => route('vendors.index', ['q' => $v->name], false)]);

        $add('Assets', 'assets.view',
            Asset::where(fn ($w) => $w->where('name', 'like', $like)->orWhere('asset_no', 'like', $like)->orWhere('serial_no', 'like', $like)),
            fn ($a) => ['label' => $a->name, 'sub' => trim(label($a->type).' · '.($a->asset_no ?: '')), 'url' => route('assets.show', $a, false)]);

        return response()->json(['groups' => $groups]);
    }
}
