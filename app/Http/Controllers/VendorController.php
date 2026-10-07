<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use Illuminate\Database\Eloquent\Model;

class VendorController extends CrudController
{
    protected string $model = Vendor::class;
    protected string $module = 'vendors';
    protected string $route = 'vendors';
    protected string $title = 'Vendors';
    protected string $singular = 'Vendor';
    protected array $searchable = ['name', 'phone', 'contact_person'];
    protected string $orderBy = 'name';
    protected string $orderDir = 'asc';

    protected function fields(?Model $m = null): array
    {
        return [
            $this->f('name', 'Name', 'text', ['required' => true]),
            $this->f('contact_person', 'Contact person'),
            $this->f('phone', 'Phone', 'tel'),
            $this->f('email', 'Email', 'email', ['rules' => 'nullable|email']),
            $this->f('address', 'Address', 'textarea', ['span' => 2]),
            $this->f('notes', 'Notes', 'textarea', ['span' => 2]),
            $this->f('is_active', 'Active', 'checkbox', ['default' => true]),
        ];
    }

    protected function columns(): array
    {
        return [
            [__('Name'), 'name'],
            [__('Phone'), 'phone'],
            [__('Contact'), 'contact_person'],
            [__('Total spent'), fn ($r) => \App\Models\Expense::withoutGlobalScopes()->where('vendor_id', $r->id)->sum('base_amount'), ['money' => true]],
            [__('We owe'), fn ($r) => $r->payable(), ['money' => true]],
        ];
    }

    protected function beforeDelete(Model $m): ?string
    {
        return \App\Models\Expense::withoutGlobalScopes()->where('vendor_id', $m->id)->exists() || $m->payments()->exists()
            ? __('Vendor has expenses or payments; deactivate instead.') : null;
    }

    /** Vendor ledger: credit bills and payments with a running balance, open bills and aging. */
    public function show(\Illuminate\Http\Request $r, $id)
    {
        $this->authorizeAction('view');
        $v = \App\Models\Vendor::findOrFail($id);

        $lines = collect();
        foreach ($v->creditBills()->with('order')->get() as $e) {
            $lines->push(['date' => $e->date, 'o' => 0, 'desc' => __('Bill').': '.($e->title ?: ($e->category?->name ?: label($e->type))).($e->order ? ' ('.$e->order->order_no.')' : '')
                .($e->due_date ? ' · '.__('due').' '.fmt_date($e->due_date) : ''), 'bill' => (float) $e->base_amount, 'paid' => 0.0, 'id' => $e->id]);
        }
        foreach ($v->payments()->get() as $p) {
            $lines->push(['date' => $p->date, 'o' => 1, 'desc' => __('Payment').' ('.label($p->mode).')'.($p->reference ? " · {$p->reference}" : ''), 'bill' => 0.0, 'paid' => (float) $p->amount, 'id' => $p->id]);
        }
        $bal = 0.0;
        $lines = $lines->sortBy([['date', 'asc'], ['o', 'asc']])->values()->map(function ($l) use (&$bal) {
            $bal = round($bal + $l['bill'] - $l['paid'], 2);

            return $l + ['balance' => $bal];
        });

        if ($fmt = $r->query('export')) {
            $head = [__('Date'), __('Details'), __('Bills'), __('Paid'), __('Balance')];
            $rows = $lines->map(fn ($l) => [fmt_date($l['date']), $l['desc'], $l['bill'] ?: '', $l['paid'] ?: '', $l['balance']])->all();

            return \App\Exports\TableExport::respond($v->name.' ledger', $head, $rows, $fmt, [__('Total billed') => $v->billed(), __('Total paid') => $v->paid(), __('We owe') => $v->payable()]);
        }

        $open = $v->openBills();
        $aging = ['current' => 0.0, 'd30' => 0.0, 'd60' => 0.0, 'd60p' => 0.0];
        foreach ($open as $b) {
            $late = (int) now()->startOfDay()->diffInDays($b['due']->startOfDay(), false) * -1; // days past due
            $aging[$late <= 0 ? 'current' : ($late <= 30 ? 'd30' : ($late <= 60 ? 'd60' : 'd60p'))] += $b['left'];
        }

        return view('vendors.show', ['v' => $v, 'lines' => $lines, 'open' => $open, 'aging' => $aging, 'payable' => $v->payable(),
            'spent' => (float) \App\Models\Expense::withoutGlobalScopes()->where('vendor_id', $v->id)->sum('base_amount')]);
    }
}
