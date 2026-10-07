<?php

namespace App\Http\Controllers;

use App\Models\GarmentType;
use Illuminate\Database\Eloquent\Model;

class GarmentTypeController extends CrudController
{
    protected string $model = GarmentType::class;
    protected string $module = 'master';
    protected string $route = 'garments';
    protected string $title = 'Garment Types & Rate Card';
    protected string $singular = 'Garment type';
    protected string $orderBy = 'name';
    protected string $orderDir = 'asc';

    protected function fields(?Model $m = null): array
    {
        return [
            $this->f('name', 'Garment (Shirt, Pant, 2 Piece, Kaftan…)', 'text', ['required' => true,
                'rules' => 'string|max:100|unique:garment_types,name'.($m ? ",{$m->id}" : '')]),
            $this->f('default_rate', 'Default stitching rate per piece', 'number', ['required' => true, 'step' => '0.01', 'rules' => 'numeric|min:0',
                'help' => __('Individual workers can have their own rate on the worker page.')]),
            $this->f('is_active', 'Active', 'checkbox', ['default' => true]),
        ];
    }

    protected function columns(): array
    {
        return [
            [__('Garment'), 'name'],
            [__('Default rate'), 'default_rate', ['money' => true]],
            [__('Status'), fn ($r) => $r->is_active ? 'active' : 'cancelled', ['badge' => true, 'label' => true]],
        ];
    }

    protected function beforeDelete(Model $m): ?string
    {
        return \App\Models\WorkEntry::withoutGlobalScopes()->where('garment_type_id', $m->id)->exists() ? __('Garment is used by work entries; deactivate instead.') : null;
    }
}
