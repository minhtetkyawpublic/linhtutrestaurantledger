<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminPermissionController extends Controller
{
    public function permissions()
    {
        return Permission::query()
            ->orderBy('name')
            ->get(['id', 'name', 'label']);
    }

    public function roles()
    {
        return Role::query()
            ->with('permissions:id,name,label')
            ->orderBy('name')
            ->get();
    }

    public function staff()
    {
        return User::query()
            ->with([
                'roles:id,name,display_name',
                'roles.permissions:id,name,label',
                'directPermissions:id,name,label',
            ])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'is_disabled', 'ui_locale', 'created_at']);
    }

    public function createStaff(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
            'permission_ids' => ['nullable', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);

        $user = DB::transaction(function () use ($request, $validated) {
            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'is_disabled' => false,
                'ui_locale' => 'en',
            ]);
            $user->roles()->sync($validated['role_ids'] ?? []);
            $user->directPermissions()->sync($validated['permission_ids'] ?? []);

            $this->audit($request, 'staff_created', $user, [
                'role_ids' => $validated['role_ids'] ?? [],
                'permission_ids' => $validated['permission_ids'] ?? [],
            ]);

            return $user;
        });

        return response()->json($user->load('roles:id,name,display_name', 'directPermissions:id,name,label'), 201);
    }

    public function updateStaff(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
            'permission_ids' => ['nullable', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);

        DB::transaction(function () use ($request, $validated, $user) {
            $before = [
                'name' => $user->name,
                'email' => $user->email,
                'role_ids' => $user->roles()->pluck('roles.id')->all(),
                'permission_ids' => $user->directPermissions()->pluck('permissions.id')->all(),
            ];

            $user->update([
                'name' => $validated['name'],
                'email' => $validated['email'],
            ]);
            $user->roles()->sync($validated['role_ids'] ?? []);
            $user->directPermissions()->sync($validated['permission_ids'] ?? []);

            $this->audit($request, 'staff_updated', $user, [
                'before' => $before,
                'after' => [
                    'name' => $user->name,
                    'email' => $user->email,
                    'role_ids' => $validated['role_ids'] ?? [],
                    'permission_ids' => $validated['permission_ids'] ?? [],
                ],
            ]);
        });

        return response()->json($user->load('roles:id,name,display_name', 'directPermissions:id,name,label'));
    }

    public function resetPassword(Request $request, User $user)
    {
        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $user->update(['password' => Hash::make($validated['password'])]);
        $this->audit($request, 'staff_password_reset', $user, [], $validated['reason']);

        return response()->json(['message' => 'Password reset']);
    }

    public function updateRolePermissions(Request $request, Role $role)
    {
        $validated = $request->validate([
            'permission_ids' => ['required', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $before = $role->permissions()->pluck('permissions.id')->all();
        $role->permissions()->sync($validated['permission_ids']);
        $this->audit($request, 'role_permissions_updated', $role, [
            'before' => $before,
            'after' => $validated['permission_ids'],
        ], $validated['reason']);

        return response()->json($role->load('permissions:id,name,label'));
    }

    public function toggleUserDisable(Request $request, User $user)
    {
        $validated = $request->validate([
            'is_disabled' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        if ($request->user()->is($user) && $validated['is_disabled']) {
            return response()->json(['message' => 'You cannot disable your own account.'], 422);
        }

        $before = $user->is_disabled;
        $user->is_disabled = $validated['is_disabled'];
        $user->save();
        $this->audit($request, 'staff_status_updated', $user, [
            'before' => $before,
            'after' => $user->is_disabled,
        ], $validated['reason']);

        return response()->json([
            'message' => 'User updated',
            'user' => $user,
            'reason' => $validated['reason'],
        ]);
    }

    public function auditHistory(Request $request)
    {
        $query = AuditLog::query()
            ->with('actor:id,name,email')
            ->latest('id');

        if ($request->filled('action')) {
            $query->where('action', $request->string('action'));
        }

        return $query->limit(200)->get();
    }

    private function audit(Request $request, string $action, Model $subject, array $changes = [], ?string $reason = null): void
    {
        AuditLog::query()->create([
            'actor_user_id' => $request->user()?->id,
            'action' => $action,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'changes' => $changes,
            'reason' => $reason,
        ]);
    }
}
