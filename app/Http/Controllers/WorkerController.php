<?php

namespace App\Http\Controllers;

use App\Models\GarmentType;
use App\Models\User;
use App\Models\Worker;
use App\Support\Ledger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class WorkerController extends CrudController
{
    protected string $model = Worker::class;
    protected string $module = 'workers';
    protected string $route = 'workers';
    protected string $title = 'Workers & Staff';
    protected string $singular = 'Worker';
    protected array $searchable = ['name', 'phone', 'cnic'];
    protected string $orderBy = 'name';
    protected string $orderDir = 'asc';

    protected function fields(?Model $m = null): array
    {
        return [
            $this->f('name', 'Name', 'text', ['required' => true]),
            $this->f('phone', 'Phone', 'tel'),
            $this->f('cnic', 'CNIC'),
            $this->f('type', 'Worker type', 'select', ['required' => true, 'default' => 'freelancer',
                'options' => ['freelancer' => __('Freelancer (piece-rate)'), 'salaried' => __('Employee (monthly salary)')]]),
            $this->f('pay_cycle', 'Payment cycle', 'select', ['required' => true, 'default' => 'weekly',
                'options' => ['weekly' => __('Weekly'), 'biweekly' => __('Every 2 weeks'), 'monthly' => __('Monthly')]]),
            $this->f('monthly_salary', 'Monthly salary', 'number', ['step' => '0.01', 'default' => 0, 'rules' => 'nullable|numeric|min:0']),
            $this->f('opening_due', 'Previous due amount (we owe this worker)', 'number', ['step' => '0.01', 'default' => 0, 'rules' => 'nullable|numeric|between:-99999999,99999999',
                'help' => __('Pending payment from before you started using this app. It is added to the worker\'s balance; payments and advances reduce it. Use a negative number if the worker owes you.')]),
            $this->f('opening_date', 'Previous due as of', 'date', ['help' => __('The date this previous due was valid (empty = today).')]),
            $this->f('joined_at', 'Joining date', 'date'),
            $this->f('user_id', 'Login account (optional)', 'select', ['options' => opts(User::class), 'help' => __('Lets this worker see their own ledger')]),
            $this->f('notes', 'Notes', 'textarea', ['span' => 2]),
            $this->f('is_active', 'Active', 'checkbox', ['default' => true]),
        ];
    }

    protected function filters(): array
    {
        return [
            $this->flt('type', 'Type', ['freelancer' => __('Freelancer'), 'salaried' => __('Employee')]),
            $this->flt('pay_cycle', 'Cycle', ['weekly' => __('Weekly'), 'biweekly' => __('Every 2 weeks'), 'monthly' => __('Monthly')]),
        ];
    }

    protected function columns(): array
    {
        return [
            [__('Name'), 'name'],
            [__('Type'), 'type', ['label' => true]],
            [__('Cycle'), 'pay_cycle', ['label' => true]],
            [__('Previous due'), 'opening_due', ['money' => true]],
            [__('Balance'), fn ($r) => $r->balance(), ['money' => true]],
            [__('Status'), fn ($r) => $r->is_active ? 'active' : 'cancelled', ['badge' => true, 'label' => true]],
        ];
    }

    protected function formData(?Model $m): array
    {
        return [
            'garments' => GarmentType::where('is_active', true)->get(),
            'rates' => $m ? $m->rates()->pluck('rate', 'garment_type_id')->all() : [],
        ];
    }

    protected function prepare(array $data, ?Model $m, Request $r): array
    {
        $data['opening_due'] = round((float) ($data['opening_due'] ?? 0), 2);
        if ($data['opening_due'] == 0.0) {
            $data['opening_date'] = null;                                   // nothing carried forward
        } elseif (empty($data['opening_date'])) {
            $data['opening_date'] = $m?->opening_date?->toDateString() ?? now()->toDateString();
        }

        return $data;
    }

    protected function saved(Model $m, Request $r, bool $created): void
    {
        foreach ((array) $r->input('rates', []) as $gid => $rate) {
            if ($rate === null || $rate === '') {
                $m->rates()->where('garment_type_id', $gid)->delete();
            } else {
                $m->rates()->updateOrCreate(['garment_type_id' => $gid], ['rate' => (float) $rate]);
            }
        }
    }

    protected function beforeDelete(Model $m): ?string
    {
        return $m->workEntries()->exists() || $m->payments()->exists() || $m->salaryEntries()->exists()
            ? __('Worker has records; deactivate instead.') : null;
    }

    /** Ledger / balance sheet of a worker with period filters and Pending / Partial / Paid status. */
    public function show(Request $r, $id)
    {
        $this->authorizeAction('view');

        return Ledger::view($r, Worker::findOrFail($id));
    }

    public function myLedger(Request $r)
    {
        abort_unless(auth()->user()->can('my.ledger'), 403);
        $w = Worker::where('user_id', auth()->id())->first();
        if (! $w) {
            return view('workers.no-profile');
        }

        return Ledger::view($r, $w, true);
    }
}
