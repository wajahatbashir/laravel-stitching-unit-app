<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Model;

class CustomerController extends CrudController
{
    protected string $model = Customer::class;
    protected string $module = 'customers';
    protected string $route = 'customers';
    protected string $title = 'Customers';
    protected string $singular = 'Customer';
    protected array $searchable = ['name', 'phone', 'contact_person'];
    protected string $orderBy = 'name';
    protected string $orderDir = 'asc';

    protected function fields(?Model $m = null): array
    {
        return [
            $this->f('name', 'Customer name', 'text', ['required' => true]),
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
            [__('Orders'), fn ($r) => $r->orders()->count()],
            [__('Receivable'), fn ($r) => $r->receivable(), ['money' => true]],
            [__('Advance held'), fn ($r) => $r->advance(), ['money' => true]],
        ];
    }

    public function show($id)
    {
        $this->authorizeAction('view');
        $c = Customer::findOrFail($id);

        return view('customers.show', [
            'c' => $c,
            'orders' => $c->orders()->latest('date')->get(),
            'invoices' => $c->invoices()->latest('date')->get(),
            'payments' => $c->payments()->withoutGlobalScopes()->with('invoice', 'allocations.invoice')->orderByDesc('date')->orderByDesc('id')->get(),
        ]);
    }

    protected function beforeDelete(Model $m): ?string
    {
        return $m->orders()->exists() || $m->invoices()->exists() ? __('Customer has orders/invoices; deactivate instead.') : null;
    }
}
