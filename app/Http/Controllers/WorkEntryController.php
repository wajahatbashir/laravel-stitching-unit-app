<?php

namespace App\Http\Controllers;

use App\Models\GarmentType;
use App\Models\Order;
use App\Models\Worker;
use App\Models\WorkEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class WorkEntryController extends CrudController
{
    protected string $model = WorkEntry::class;
    protected string $module = 'work_entries';
    protected string $route = 'work-entries';
    protected string $title = 'Work Entries';
    protected string $singular = 'Work entry';
    protected array $with = ['worker', 'order', 'garmentType'];
    protected array $searchable = ['worker.name', 'order.order_no', 'notes'];
    protected ?string $dateColumn = 'date';
    protected ?string $sumColumn = 'amount';
    protected bool $offline = true;
    protected string $formView = 'work_entries.form';

    protected function fields(?Model $m = null): array
    {
        return [
            $this->f('worker_id', 'Worker', 'select', ['required' => true, 'options' => opts(Worker::class, 'name', fn ($q) => $q->where('is_active', true))]),
            $this->f('date', 'Date', 'date', ['required' => true, 'default' => now()->toDateString()]),
            $this->f('order_id', 'Order', 'select', ['options' => opts(Order::class, 'order_no')]),
            $this->f('garment_type_id', 'Garment', 'select', ['required' => true, 'options' => opts(GarmentType::class, 'name', fn ($q) => $q->where('is_active', true))]),
            $this->f('qty', 'Pieces', 'number', ['required' => true, 'rules' => 'integer|min:1']),
            $this->f('rate', 'Rate per piece', 'number', ['step' => '0.01', 'rules' => 'nullable|numeric|min:0',
                'help' => __('Auto-filled from the rate card; change if needed')]),
            $this->f('notes', 'Notes', 'textarea', ['span' => 2]),
        ];
    }

    protected function filters(): array
    {
        return [
            $this->flt('worker_id', 'Worker', opts(Worker::class)),
            $this->flt('order_id', 'Order', opts(Order::class, 'order_no')),
            $this->flt('garment_type_id', 'Garment', opts(GarmentType::class)),
        ];
    }

    protected function columns(): array
    {
        return [
            [__('Date'), 'date'],
            [__('Worker'), 'worker.name'],
            [__('Order'), 'order.order_no'],
            [__('Garment'), 'garmentType.name'],
            [__('Pieces'), 'qty'],
            [__('Rate'), 'rate', ['money' => true]],
            [__('Amount'), 'amount', ['money' => true]],
        ];
    }

    protected function formData(?Model $m): array
    {
        // rate card map so the form can auto-fill the rate, also while offline
        $default = GarmentType::pluck('default_rate', 'id');
        $map = [];
        foreach (Worker::with('rates')->get() as $w) {
            $over = $w->rates->pluck('rate', 'garment_type_id');
            $map[$w->id] = $default->map(fn ($d, $gid) => (float) ($over[$gid] ?? $d))->all();
        }

        return ['rateMap' => $map];
    }

    protected function prepare(array $data, ?Model $m, Request $r): array
    {
        if (! isset($data['rate']) || $data['rate'] === '') {
            $data['rate'] = Worker::findOrFail($data['worker_id'])->rateFor((int) $data['garment_type_id']);
        }
        $data['amount'] = round($data['qty'] * $data['rate'], 2);

        return $data;
    }
}
