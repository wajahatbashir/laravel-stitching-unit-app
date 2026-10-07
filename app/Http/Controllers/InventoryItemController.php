<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use Illuminate\Database\Eloquent\Model;

class InventoryItemController extends CrudController
{
    protected string $model = InventoryItem::class;
    protected string $module = 'inventory';
    protected string $route = 'inventory-items';
    protected string $title = 'Inventory Items';
    protected string $singular = 'Item';
    protected array $searchable = ['name'];
    protected string $orderBy = 'name';
    protected string $orderDir = 'asc';

    protected function fields(?Model $m = null): array
    {
        return [
            $this->f('name', 'Item name (lace, organza, lining…)', 'text', ['required' => true]),
            $this->f('unit', 'Unit', 'text', ['required' => true, 'default' => 'pcs', 'help' => 'pcs, meter, yard, kg']),
            $this->f('min_stock', 'Low-stock alert at', 'number', ['step' => '0.01', 'default' => 0, 'rules' => 'nullable|numeric|min:0']),
            $this->f('notes', 'Notes', 'textarea', ['span' => 2]),
            $this->f('is_active', 'Active', 'checkbox', ['default' => true]),
        ];
    }

    protected function columns(): array
    {
        return [
            [__('Item'), 'name'],
            [__('Unit'), 'unit'],
            [__('In stock'), fn ($r) => rtrim(rtrim(number_format($r->stock(), 2), '0'), '.')],
            [__('Min'), 'min_stock'],
            [__('Status'), fn ($r) => $r->stock() <= (float) $r->min_stock ? 'pending' : 'active', ['badge' => true, 'label' => true]],
        ];
    }

    protected function beforeDelete(Model $m): ?string
    {
        return $m->movements()->withoutGlobalScopes()->exists() ? __('Item has stock movements; deactivate instead.') : null;
    }
}
