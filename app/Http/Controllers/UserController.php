<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

class UserController extends CrudController
{
    protected string $model = User::class;
    protected string $module = 'users';
    protected string $route = 'users';
    protected string $title = 'Users';
    protected string $singular = 'User';
    protected array $searchable = ['name', 'email', 'phone'];
    protected string $orderBy = 'name';
    protected string $orderDir = 'asc';

    private function roleOptions(): array
    {
        $q = Role::query()->orderBy('name');
        if (! auth()->user()->hasRole('Super Admin')) {
            $q->where('name', '!=', 'Super Admin');
        }

        return $q->pluck('name', 'name')->all();
    }

    protected function fields(?Model $m = null): array
    {
        return [
            $this->f('name', 'Name', 'text', ['required' => true]),
            $this->f('email', 'Email (login)', 'email', ['required' => true, 'rules' => 'email|unique:users,email'.($m ? ",{$m->id}" : '')]),
            $this->f('phone', 'Phone / WhatsApp', 'tel'),
            $this->f('password', $m ? 'New password (leave blank to keep)' : 'Password', 'password',
                ['required' => ! $m, 'rules' => ($m ? 'nullable' : 'required').'|string|min:8']),
            $this->f('role', 'Role', 'select', ['required' => true, 'options' => $this->roleOptions(),
                'default' => 'User', 'rules' => 'string', 'virtual' => true, 'value' => $m?->getRoleNames()->first()]),
            $this->f('locale', 'Language', 'select', ['default' => 'en', 'options' => ['en' => 'English', 'ur' => 'اردو']]),
            $this->f('is_active', 'Active (can log in)', 'checkbox', ['default' => true]),
        ];
    }

    protected function columns(): array
    {
        return [
            [__('Name'), 'name'],
            [__('Email'), 'email'],
            [__('Role'), fn ($r) => $r->getRoleNames()->implode(', ')],
            [__('Status'), fn ($r) => $r->is_active ? 'active' : 'cancelled', ['badge' => true, 'label' => true]],
        ];
    }

    protected function prepare(array $data, ?Model $m, Request $r): array
    {
        if (! array_key_exists('password', $data) || $data['password'] === null || $data['password'] === '') {
            unset($data['password']);
        }
        if ($data['role'] === 'Super Admin' && ! auth()->user()->hasRole('Super Admin')) {
            abort(403);
        }
        $this->pendingRole = $data['role'];
        unset($data['role']);

        return $data;
    }

    private ?string $pendingRole = null;

    protected function saved(Model $m, Request $r, bool $created): void
    {
        $m->syncRoles([$this->pendingRole]);
        if ($r->boolean('reset_two_factor')) {
            \App\Services\TwoFactor::clear($m);
        }
    }

    protected function beforeDelete(Model $m): ?string
    {
        return $m->id === auth()->id() ? __('You cannot delete your own account.') : null;
    }
}
