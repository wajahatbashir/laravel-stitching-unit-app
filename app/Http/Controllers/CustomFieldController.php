<?php

namespace App\Http\Controllers;

use App\Models\CustomField;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CustomFieldController extends CrudController
{
    protected string $model = CustomField::class;
    protected string $module = 'master';
    protected string $route = 'custom-fields';
    protected string $title = 'Unit Expense Custom Fields';
    protected string $singular = 'Custom field';
    protected string $orderBy = 'sort';
    protected string $orderDir = 'asc';

    protected function fields(?Model $m = null): array
    {
        return [
            $this->f('label', 'Field label', 'text', ['required' => true]),
            $this->f('type', 'Field type', 'select', ['required' => true, 'options' => [
                'text' => __('Text'), 'number' => __('Number'), 'date' => __('Date'), 'textarea' => __('Long text'),
                'select' => __('Dropdown'), 'image' => __('Image upload')]]),
            $this->f('options', 'Dropdown options (comma separated)', 'text'),
            $this->f('sort', 'Order', 'number', ['default' => 0, 'rules' => 'nullable|integer']),
            $this->f('required', 'Required', 'checkbox'),
            $this->f('is_active', 'Active', 'checkbox', ['default' => true]),
        ];
    }

    protected function columns(): array
    {
        return [
            [__('Label'), 'label'],
            [__('Type'), 'type', ['label' => true]],
            [__('Required'), fn ($r) => $r->required ? 'yes' : '—', ['label' => true]],
            [__('Status'), fn ($r) => $r->is_active ? 'active' : 'cancelled', ['badge' => true, 'label' => true]],
        ];
    }

    protected function prepare(array $data, ?Model $m, Request $r): array
    {
        $data['sort'] = $data['sort'] ?? 0;
        if (! $m) {
            $data['entity'] = 'unit_expense';
            $key = Str::slug($data['label'], '_') ?: 'field';
            $base = $key;
            for ($i = 2; CustomField::where('entity', 'unit_expense')->where('key', $key)->exists(); $i++) {
                $key = "{$base}_$i";
            }
            $data['key'] = $key;
        }

        return $data;
    }
}
