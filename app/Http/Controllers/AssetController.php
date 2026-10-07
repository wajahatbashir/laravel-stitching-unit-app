<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Model;

class AssetController extends CrudController
{
    protected string $model = Asset::class;
    protected string $module = 'assets';
    protected string $route = 'assets';
    protected string $title = 'Assets';
    protected string $singular = 'Asset';
    protected array $with = ['vendor'];
    protected array $searchable = ['name', 'asset_no', 'brand', 'model', 'serial_no'];
    protected ?string $dateColumn = 'purchase_date';
    protected ?string $sumColumn = 'cost';

    protected function fields(?Model $m = null): array
    {
        return [
            $this->f('type', 'Type', 'select', ['required' => true, 'default' => 'machine',
                'options' => ['machine' => __('Machine'), 'furniture' => __('Furniture'), 'other' => __('Other')]]),
            $this->f('name', 'Name', 'text', ['required' => true]),
            $this->f('asset_no', 'Machine / Asset number'),
            $this->f('brand', 'Brand'),
            $this->f('model', 'Model'),
            $this->f('serial_no', 'Serial number'),
            $this->f('cost', 'Cost', 'number', ['required' => true, 'rules' => 'numeric|min:0', 'step' => '0.01']),
            $this->f('purchase_date', 'Purchase date', 'date'),
            $this->f('vendor_id', 'Purchased from', 'select', ['options' => opts(Vendor::class)]),
            $this->f('location', 'Location'),
            $this->f('status', 'Status', 'select', ['default' => 'active',
                'options' => ['active' => __('Active'), 'repair' => __('Under repair'), 'sold' => __('Sold'), 'scrapped' => __('Scrapped')]]),
            $this->f('notes', 'Notes', 'textarea', ['span' => 2]),
            $this->f('files', 'Photos / documents', 'files', ['span' => 2]),
        ];
    }

    protected function filters(): array
    {
        return [
            $this->flt('type', 'Type', ['machine' => __('Machine'), 'furniture' => __('Furniture'), 'other' => __('Other')]),
            $this->flt('status', 'Status', ['active' => __('Active'), 'repair' => __('Under repair'), 'sold' => __('Sold'), 'scrapped' => __('Scrapped')]),
        ];
    }

    protected function columns(): array
    {
        return [
            [__('Name'), 'name'],
            [__('No.'), 'asset_no'],
            [__('Type'), 'type', ['label' => true]],
            [__('Brand'), 'brand'],
            [__('Cost'), 'cost', ['money' => true]],
            [__('Status'), 'status', ['badge' => true, 'label' => true]],
        ];
    }

    protected function saved(Model $m, \Illuminate\Http\Request $r, bool $created): void
    {
        $this->saveFiles($m, $r);
    }

    public function show($id)
    {
        $this->authorizeAction('view');
        $a = Asset::with('vendor', 'attachments')->findOrFail($id);
        $exp = $a->expenses()->withoutGlobalScopes()->with('category')->latest('date')->get();

        return view('assets.show', ['a' => $a, 'expenses' => $exp]);
    }
}
