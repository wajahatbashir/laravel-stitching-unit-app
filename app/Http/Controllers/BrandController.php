<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use Illuminate\Database\Eloquent\Model;

/** Admin → Brands: the list shown in the Brand dropdown of an order. */
class BrandController extends CrudController
{
    protected string $model = Brand::class;
    protected string $module = 'master';
    protected string $route = 'brands';
    protected string $title = 'Brands';
    protected string $singular = 'Brand';
    protected array $searchable = ['name'];
    protected string $orderBy = 'name';
    protected string $orderDir = 'asc';

    protected function fields(?Model $m = null): array
    {
        return [
            $this->f('name', 'Brand name (Maria.B., Noore, Parishay…)', 'text', ['required' => true,
                'rules' => 'string|max:100|unique:brands,name'.($m ? ",{$m->id}" : '')]),
            $this->f('is_active', 'Active', 'checkbox', ['default' => true, 'help' => __('Inactive brands no longer appear in the order form; old orders keep them.')]),
        ];
    }

    protected function columns(): array
    {
        return [
            [__('Brand'), 'name'],
            [__('Orders'), fn ($r) => $r->orders()->withoutGlobalScopes()->count()],
            [__('Status'), fn ($r) => $r->is_active ? 'active' : 'cancelled', ['badge' => true, 'label' => true]],
        ];
    }

    protected function beforeDelete(Model $m): ?string
    {
        return $m->orders()->withoutGlobalScopes()->exists() ? __('Brand is used by orders; deactivate it instead.') : null;
    }
}
