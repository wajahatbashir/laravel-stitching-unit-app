<?php

namespace App\Http\Controllers;

use App\Models\Worker;
use App\Models\WorkerPayment;
use App\Services\Notifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class WorkerPaymentController extends CrudController
{
    protected string $model = WorkerPayment::class;
    protected string $module = 'worker_payments';
    protected string $route = 'worker-payments';
    protected string $title = 'Worker Payments & Advances';
    protected string $singular = 'Payment';
    protected array $with = ['worker'];
    protected array $searchable = ['worker.name', 'notes'];
    protected ?string $dateColumn = 'date';
    protected ?string $sumColumn = 'amount';
    protected bool $offline = true;

    protected function fields(?Model $m = null): array
    {
        return [
            $this->f('worker_id', 'Worker', 'select', ['required' => true, 'options' => opts(Worker::class)]),
            $this->f('type', 'Type', 'select', ['required' => true, 'default' => 'payment',
                'options' => ['payment' => __('Payment (salary / piece-rate)'), 'advance' => __('Advance')]]),
            $this->f('date', 'Payment date', 'date', ['required' => true, 'default' => now()->toDateString()]),
            $this->f('amount', 'Amount paid', 'number', ['required' => true, 'step' => '0.01', 'rules' => 'numeric|min:0.01',
                'help' => __('Partial payments are fine — the unpaid balance carries forward automatically.')]),
            $this->f('mode', 'Paid via', 'select', ['required' => true, 'default' => 'cash', 'options' => ['cash' => __('Cash'), 'bank' => __('Bank')]]),
            $this->f('period_from', 'Period from', 'date'),
            $this->f('period_to', 'Period to', 'date'),
            $this->f('notes', 'Notes', 'textarea', ['span' => 2]),
        ];
    }

    /** Payment voucher page: details + PDF / WhatsApp share. */
    public function show($id)
    {
        $this->authorizeAction('view');

        return view('worker-payments.show', ['p' => WorkerPayment::with('worker')->findOrFail($id)]);
    }

    protected function filters(): array
    {
        return [
            $this->flt('worker_id', 'Worker', opts(Worker::class)),
            $this->flt('type', 'Type', ['payment' => __('Payment'), 'advance' => __('Advance')]),
            $this->flt('mode', 'Paid via', ['cash' => __('Cash'), 'bank' => __('Bank')]),
        ];
    }

    protected function columns(): array
    {
        return [
            [__('Date'), 'date'],
            [__('Worker'), 'worker.name'],
            [__('Type'), 'type', ['badge' => true, 'label' => true]],
            [__('Via'), 'mode', ['label' => true]],
            [__('Amount'), 'amount', ['money' => true]],
            [__('Period'), fn ($r) => $r->period_from ? fmt_date($r->period_from).' → '.fmt_date($r->period_to) : '—'],
        ];
    }

    protected function saved(Model $m, Request $r, bool $created): void
    {
        if ($created) {
            Notifier::send('worker_payment', __('Paid :a to :w', ['a' => money($m->amount), 'w' => $m->worker->name]),
                label($m->type).' — '.fmt_date($m->date), route('workers.show', $m->worker_id));
        }
    }

    public function done(Request $r, string $msg)
    {
        if (! $r->expectsJson() && ($w = $r->input('back_to_worker'))) {
            return redirect()->route('workers.show', $w)->with('success', $msg);
        }

        return parent::done($r, $msg);
    }
}
