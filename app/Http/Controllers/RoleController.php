<?php

namespace App\Http\Controllers;

use App\Support\Perms;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Roles & permission matrix — admin decides what each worker/user role can see and do. */
class RoleController extends Controller
{
    private function guard(): void
    {
        abort_unless(auth()->user()->can('users.view'), 403);
    }

    public function index()
    {
        $this->guard();

        return view('roles.index', ['roles' => Role::withCount('permissions', 'users')->orderBy('id')->get()]);
    }

    public function create()
    {
        $this->guard();

        return view('roles.form', ['role' => null, 'granted' => []]);
    }

    public function edit(Role $role)
    {
        $this->guard();

        return view('roles.form', ['role' => $role, 'granted' => $role->permissions->pluck('name')->all()]);
    }

    public function store(Request $r)
    {
        abort_unless(auth()->user()->can('users.create'), 403);
        $r->validate(['name' => 'required|string|max:50|unique:roles,name']);
        $role = Role::create(['name' => $r->name, 'guard_name' => 'web']);
        $role->syncPermissions($this->clean($r));

        return redirect()->route('roles.index')->with('success', __('Role created.'));
    }

    public function update(Request $r, Role $role)
    {
        abort_unless(auth()->user()->can('users.edit'), 403);
        abort_if($role->name === 'Super Admin', 403);
        $r->validate(['name' => 'required|string|max:50|unique:roles,name,'.$role->id]);
        if (! in_array($role->name, ['Admin', 'User'])) {
            $role->update(['name' => $r->name]);
        }
        $role->syncPermissions($this->clean($r));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return redirect()->route('roles.index')->with('success', __('Role updated.'));
    }

    public function destroy(Role $role)
    {
        abort_unless(auth()->user()->can('users.delete'), 403);
        if (in_array($role->name, ['Super Admin', 'Admin', 'User']) || $role->users()->exists()) {
            return back()->withErrors(['delete' => __('Built-in roles or roles with users cannot be deleted.')]);
        }
        $role->delete();

        return back()->with('success', __('Role deleted.'));
    }

    private function clean(Request $r): array
    {
        return array_values(array_intersect((array) $r->input('permissions', []), Perms::all()));
    }
}
