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
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

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

    public function staff(Request $request)
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = trim((string) ($validated['q'] ?? ''));

        return User::query()
            ->with([
                'roles:id,name,display_name',
                'roles.permissions:id,name,label',
                'directPermissions:id,name,label',
            ])
            ->when($query !== '', function ($staff) use ($query) {
                $staff->where(function ($search) use ($query) {
                    $search->where('name', 'like', "%{$query}%")
                        ->orWhere('email', 'like', "%{$query}%");
                });
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($validated['per_page'] ?? 25, [
                'id',
                'name',
                'email',
                'is_disabled',
                'ui_locale',
                'created_at',
            ]);
    }

    public function createStaff(Request $request)
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'max:255', Password::min(12)->letters()->mixedCase()->numbers()->symbols()],
            'role_ids' => ['nullable', 'array', 'max:100'],
            'role_ids.*' => ['integer', 'distinct', 'exists:roles,id'],
            'permission_ids' => ['nullable', 'array', 'max:100'],
            'permission_ids.*' => ['integer', 'distinct', 'exists:permissions,id'],
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
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role_ids' => ['nullable', 'array', 'max:100'],
            'role_ids.*' => ['integer', 'distinct', 'exists:roles,id'],
            'permission_ids' => ['nullable', 'array', 'max:100'],
            'permission_ids.*' => ['integer', 'distinct', 'exists:permissions,id'],
        ]);

        DB::transaction(function () use ($request, $validated, $user) {
            Permission::query()
                ->where('name', 'manage_staff_and_permissions')
                ->lockForUpdate()
                ->firstOrFail();
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
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
            $this->assertActiveManagerRemains();

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
            'password' => ['required', 'string', 'max:255', 'confirmed', Password::min(12)->letters()->mixedCase()->numbers()->symbols()],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'remember_token' => Str::random(60),
        ])->save();
        $this->audit($request, 'staff_password_reset', $user, [], $validated['reason']);
        $this->forgetDatabaseSessionsFor($user);

        return response()->json(['message' => 'Password reset']);
    }

    public function updateRolePermissions(Request $request, Role $role)
    {
        $validated = $request->validate([
            'permission_ids' => ['required', 'array', 'max:100'],
            'permission_ids.*' => ['integer', 'distinct', 'exists:permissions,id'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($request, $role, $validated) {
            Permission::query()
                ->where('name', 'manage_staff_and_permissions')
                ->lockForUpdate()
                ->firstOrFail();
            $role = Role::query()->whereKey($role->id)->lockForUpdate()->firstOrFail();
            $before = $role->permissions()->pluck('permissions.id')->all();
            $role->permissions()->sync($validated['permission_ids']);
            $this->assertActiveManagerRemains();
            $this->audit($request, 'role_permissions_updated', $role, [
                'before' => $before,
                'after' => $validated['permission_ids'],
            ], $validated['reason']);
        });

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

        $user = DB::transaction(function () use ($request, $user, $validated) {
            Permission::query()
                ->where('name', 'manage_staff_and_permissions')
                ->lockForUpdate()
                ->firstOrFail();
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $before = $user->is_disabled;
            $user->is_disabled = $validated['is_disabled'];
            if ($user->is_disabled) {
                $user->remember_token = Str::random(60);
            }
            $user->save();
            $this->assertActiveManagerRemains();
            $this->audit($request, 'staff_status_updated', $user, [
                'before' => $before,
                'after' => $user->is_disabled,
            ], $validated['reason']);

            return $user;
        });
        if ($user->is_disabled) {
            $this->forgetDatabaseSessionsFor($user);
        }

        return response()->json([
            'message' => 'User updated',
            'user' => $user,
            'reason' => $validated['reason'],
        ]);
    }

    public function auditHistory(Request $request)
    {
        $request->validate([
            'action' => ['nullable', 'string', 'max:80'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $perPage = min(50, max(10, $request->integer('per_page', 20)));
        $query = AuditLog::query()
            ->with('actor:id,name,email')
            ->latest('id');

        if ($request->filled('action')) {
            $query->where('action', $request->string('action'));
        }

        return $query->paginate($perPage);
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

    private function forgetDatabaseSessionsFor(User $user): void
    {
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->delete();
        }
    }

    private function assertActiveManagerRemains(): void
    {
        $managerExists = User::query()
            ->where('is_disabled', false)
            ->where(function ($query) {
                $query->whereHas('directPermissions', fn ($permissions) => $permissions->where('name', 'manage_staff_and_permissions'))
                    ->orWhereHas('roles.permissions', fn ($permissions) => $permissions->where('name', 'manage_staff_and_permissions'));
            })
            ->exists();

        if (! $managerExists) {
            throw ValidationException::withMessages([
                'permissions' => ['At least one active staff manager must remain.'],
            ]);
        }
    }
}
