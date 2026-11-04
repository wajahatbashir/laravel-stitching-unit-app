<?php

namespace App\Http\Controllers;

use App\Models\CashTransfer;
use Illuminate\Database\Eloquent\Model;

/** Capital → Cash ⇄ Bank: record cash taken from the bank (ATM / counter / transfer to cash) or cash deposited into it. */
class CashTransferController extends CrudController
{
    protected string $model = CashTransfer::class;
    protected string $module = 'transfers';
    protected string $route = 'cash-transfers';
    protected string $title = 'Cash ⇄ Bank transfers';
    protected string $singular = 'Transfer';
    protected array $searchable = ['reference', 'notes'];
    protected ?string $dateColumn = 'date';
    protected ?string $sumColumn = 'amount';
    protected bool $offline = true;

    protected function fields(?Model $m = null): array
    {
        return [
            $this->f('direction', 'What happened', 'select', ['required' => true, 'default' => 'bank_to_cash', 'rules' => 'in:bank_to_cash,cash_to_bank',
                'options' => ['bank_to_cash' => __('Took cash out of the bank (bank → cash in hand)'), 'cash_to_bank' => __('Deposited cash into the bank (cash in hand → bank)')],
                'help' => __('Only moves money between cash and bank. It is not an expense and does not change profit.')]),
            $this->f('date', 'Date', 'date', ['required' => true, 'default' => now()->toDateString()]),
            $this->f('amount', 'Amount', 'number', ['required' => true, 'step' => '0.01', 'rules' => 'numeric|min:0.01']),
            $this->f('reference', 'Reference (ATM slip / transaction ID)'),
            $this->f('notes', 'Notes', 'textarea', ['span' => 2]),
        ];
    }

    protected function filters(): array
    {
        return [$this->flt('direction', 'Direction', ['bank_to_cash' => __('Bank → cash'), 'cash_to_bank' => __('Cash → bank')])];
    }

    protected function columns(): array
    {
        return [
            [__('Date'), 'date'],
            [__('Direction'), fn ($r) => $r->direction === 'bank_to_cash' ? __('Bank → cash in hand') : __('Cash in hand → bank')],
            [__('Amount'), 'amount', ['money' => true]],
            [__('Reference'), 'reference'],
            [__('Notes'), 'notes'],
        ];
    }
}
