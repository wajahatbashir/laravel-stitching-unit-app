<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\StockMovement;
use App\Services\Notifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class StockMovementController extends CrudController
{
    protected string $model = StockMovement::class;
    protected string $module = 'inventory';
    protected string $route = 'stock';
    protected string $title = 'Stock Movements';
    protected string $singular = 'Stock movement';
    protected array $with = ['item', 'order'];
    protected array $searchable = ['item.name', 'notes'];
    protected ?string $dateColumn = 'date';
    protected bool $offline = true;

    protected function fields(?Model $m = null): array
    {
        return [
            $this->f('item_id', 'Item', 'select', ['required' => true, 'options' => opts(InventoryItem::class)]),
            $this->f('type', 'Type', 'select', ['required' => true, 'default' => 'in',
                'options' => ['in' => __('Stock in (purchased)'), 'out' => __('Stock out (used)')]]),
            $this->f('date', 'Date', 'date', ['required' => true, 'default' => now()->toDateString()]),
            $this->f('qty', 'Quantity', 'number', ['required' => true, 'step' => '0.01', 'rules' => 'numeric|min:0.01']),
            $this->f('rate', 'Rate (per unit)', 'number', ['step' => '0.01', 'rules' => 'nullable|numeric|min:0']),
            $this->f('order_id', 'For order', 'select', ['options' => opts(Order::class, 'order_no')]),
            $this->f('notes', 'Notes', 'textarea', ['span' => 2]),
        ];
    }

    protected function filters(): array
    {
        return [
            $this->flt('item_id', 'Item', opts(InventoryItem::class)),
            $this->flt('type', 'Type', ['in' => __('In'), 'out' => __('Out')]),
            $this->flt('order_id', 'Order', opts(Order::class, 'order_no')),
        ];
    }

    protected function columns(): array
    {
        return [
            [__('Date'), 'date'],
            [__('Item'), 'item.name'],
            [__('Type'), 'type', ['badge' => true, 'label' => true]],
            [__('Qty'), 'qty'],
            [__('Rate'), 'rate'],
            [__('Order'), 'order.order_no'],
        ];
    }

    protected function prepare(array $data, ?Model $m, Request $r): array
    {
        $data['rate'] = $data['rate'] ?? 0;

        return $data;
    }

    protected function saved(Model $m, Request $r, bool $created): void
    {
        $item = $m->item;
        if ($m->type === 'out' && biz('low_stock_alerts') && $item->stock() <= (float) $item->min_stock) {
            Notifier::send('low_stock', __('Low stock: :i', ['i' => $item->name]), __('Remaining: :q :u', ['q' => $item->stock(), 'u' => $item->unit]));
        }
    }
}
