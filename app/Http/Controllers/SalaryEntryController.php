<?php

namespace App\Http\Controllers;

use App\Models\SalaryEntry;
use App\Models\Worker;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class SalaryEntryController extends CrudController
{
    protected string $model = SalaryEntry::class;
    protected string $module = 'salaries';
    protected string $route = 'salaries';
    protected string $title = 'Monthly Salaries';
    protected string $singular = 'Salary entry';
    protected array $with = ['worker'];
    protected array $searchable = ['worker.name'];
    protected ?string $dateColumn = 'period_month';
    protected string $orderBy = 'period_month';
    protected bool $offline = true;

    protected function fields(?Model $m = null): array
    {
        return [
            $this->f('worker_id', 'Employee', 'select', ['required' => true,
                'options' => opts(Worker::class, 'name', fn ($q) => $q->where('type', 'salaried')->where('is_active', true))]),
            $this->f('period_month', 'Month', 'month', ['required' => true, 'default' => now()->format('Y-m')]),
            $this->f('amount', 'Salary amount', 'number', ['required' => true, 'step' => '0.01', 'rules' => 'numeric|min:0']),
            $this->f('bonus', 'Bonus / overtime', 'number', ['step' => '0.01', 'default' => 0, 'rules' => 'nullable|numeric|min:0']),
            $this->f('deduction', 'Deduction (absence etc.)', 'number', ['step' => '0.01', 'default' => 0, 'rules' => 'nullable|numeric|min:0']),
            $this->f('notes', 'Notes', 'textarea', ['span' => 2]),
        ];
    }

    protected function filters(): array
    {
        return [$this->flt('worker_id', 'Employee', opts(Worker::class, 'name', fn ($q) => $q->where('type', 'salaried')))];
    }

    protected function columns(): array
    {
        return [
            [__('Month'), fn ($r) => $r->period_month->format('M Y')],
            [__('Employee'), 'worker.name'],
            [__('Salary'), 'amount', ['money' => true]],
            [__('Bonus'), 'bonus', ['money' => true]],
            [__('Deduction'), 'deduction', ['money' => true]],
            [__('Net'), fn ($r) => $r->net(), ['money' => true]],
        ];
    }

    protected function extraRules(Request $r, ?Model $m): array
    {
        return [];
    }

    protected function prepare(array $data, ?Model $m, Request $r): array
    {
        $data['period_month'] = Carbon::parse($data['period_month'].(strlen($data['period_month']) === 7 ? '-01' : ''))->startOfMonth()->toDateString();
        $data['bonus'] = $data['bonus'] ?? 0;
        $data['deduction'] = $data['deduction'] ?? 0;

        $dup = SalaryEntry::withoutGlobalScopes()->where('worker_id', $data['worker_id'])->where('period_month', $data['period_month'])
            ->when($m, fn ($q) => $q->where('id', '!=', $m->id))->exists();
        if ($dup) {
            throw \Illuminate\Validation\ValidationException::withMessages(['period_month' => __('A salary entry already exists for this employee and month.')]);
        }

        return $data;
    }

    /** One click: create this month's salary entry for every active salaried employee that has none. */
    public function generate(Request $r)
    {
        $this->authorizeAction('create');
        $month = Carbon::parse(($r->input('month') ?: now()->format('Y-m')).'-01')->startOfMonth();
        $n = 0;
        foreach (Worker::where('type', 'salaried')->where('is_active', true)->get() as $w) {
            $exists = SalaryEntry::withoutGlobalScopes()->where('worker_id', $w->id)->where('period_month', $month->toDateString())->exists();
            if (! $exists) {
                SalaryEntry::create(['worker_id' => $w->id, 'period_month' => $month->toDateString(), 'amount' => $w->monthly_salary]);
                $n++;
            }
        }

        return back()->with('success', __(':n salary entries generated for :m.', ['n' => $n, 'm' => $month->format('M Y')]));
    }
}
