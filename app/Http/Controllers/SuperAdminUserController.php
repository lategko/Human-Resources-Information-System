<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SuperAdminUserController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'role' => ['nullable', Rule::in(['super_admin', 'admin', 'hrmo', 'office_head', 'mayor', 'employee'])],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);
        $query = User::query()->with(['employee.department', 'departmentHead']);

        if (filled($filters['search'] ?? null)) {
            $search = trim($filters['search']);
            $query->where(fn ($users) => $users
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"));
        }

        if (($filters['role'] ?? null) === 'mayor') {
            $query->where(fn ($users) => $users
                ->where('role', 'mayor')
                ->orWhere(fn ($legacy) => $legacy->where('role', 'admin')->where('can_final_approve', true)));
        } elseif (filled($filters['role'] ?? null)) {
            $query->where('role', $filters['role']);
        }

        if (($filters['status'] ?? null) === 'active') {
            $query->where('is_active', true);
        } elseif (($filters['status'] ?? null) === 'inactive') {
            $query->where('is_active', false);
        }

        $users = $query->orderBy('name')->paginate(20)->withQueryString();

        return view('super-admin.users.index', [
            'users' => $users,
            'lastLogins' => AuditLog::query()
                ->where('action', 'auth.login')
                ->whereIn('user_id', $users->pluck('id'))
                ->selectRaw('user_id, MAX(created_at) as last_login_at')
                ->groupBy('user_id')
                ->pluck('last_login_at', 'user_id'),
            'filters' => $filters,
            'recentRoleChanges' => AuditLog::query()
                ->with('user:id,name')
                ->whereIn('action', ['user.created', 'user.updated', 'user.role_changed', 'user.status_changed', 'user.credentials_reset'])
                ->latest()
                ->limit(8)
                ->get(),
        ]);
    }

    public function show(User $user): View
    {
        return view('super-admin.users.show', [
            'account' => $user->load(['employee.department', 'employee.position', 'departmentHead']),
            'availableEmployees' => Employee::query()
                ->whereNull('archived_at')
                ->where(function ($employees) use ($user) {
                    $employees->whereNull('user_id')->orWhere('user_id', $user->id);
                })
                ->orderBy('last_name')
                ->get(),
            'history' => AuditLog::query()
                ->with('user:id,name')
                ->where('subject_type', User::class)
                ->where('subject_id', $user->id)
                ->latest()
                ->limit(20)
                ->get(),
        ]);
    }

    public function changeRole(Request $request, User $user, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::in(User::VALID_ROLES)],
            'employee_id' => [
                Rule::requiredIf($request->input('role') === 'employee'),
                'nullable',
                'integer',
                Rule::exists('employees', 'id')->whereNull('deleted_at')->whereNull('archived_at'),
            ],
        ]);
        abort_if($user->id === $request->user()->id, 403, 'You cannot change your own privileged role.');

        [$previousRole, $newRole] = DB::transaction(function () use ($user, $data, $auditLogger): array {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $allowedTransition = match ($lockedUser->role) {
                'employee' => in_array($data['role'], ['hrmo', 'admin', 'super_admin'], true),
                'hrmo' => in_array($data['role'], ['admin', 'employee', 'super_admin'], true),
                'admin' => ! $lockedUser->isMayor() && in_array($data['role'], ['hrmo', 'super_admin', 'employee'], true),
                'super_admin' => in_array($data['role'], ['admin', 'employee'], true),
                default => false,
            };
            abort_unless($allowedTransition, 422, 'This account cannot be changed to the selected role.');

            if ($data['role'] === 'employee') {
                $employee = Employee::query()
                    ->lockForUpdate()
                    ->findOrFail($data['employee_id']);
                abort_if(
                    $employee->user_id && $employee->user_id !== $lockedUser->id,
                    422,
                    'This employee record is already linked to another account.'
                );
                abort_if(
                    $lockedUser->employee && $lockedUser->employee->id !== $employee->id,
                    422,
                    'An existing employee account cannot be linked to a different employee record.'
                );
                $employee->user_id = $lockedUser->id;
                $employee->saveOrFail();
                $lockedUser->name = $employee->full_name;
            }

            if ($lockedUser->isSuperAdmin() && $lockedUser->is_active) {
                $activeSuperAdmins = User::query()
                    ->where('role', 'super_admin')
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->count();
                abort_if($activeSuperAdmins <= 1, 422, 'The last active Super Admin cannot be demoted.');
            }

            $previous = $lockedUser->role;
            $lockedUser->role = $data['role'];
            if ($data['role'] !== 'admin') {
                $lockedUser->can_final_approve = false;
            }
            $lockedUser->saveOrFail();
            $auditLogger->record('user.role_changed', $lockedUser, [
                'previous_role' => $previous,
                'new_role' => $lockedUser->role,
            ]);

            return [$previous, $lockedUser->role];
        });

        $message = match ([$previousRole, $newRole]) {
            ['hrmo', 'admin'] => 'HRMO account promoted to ADMIN.',
            ['admin', 'hrmo'] => 'ADMIN account demoted to HRMO.',
            ['admin', 'super_admin'] => 'ADMIN account promoted to SUPER ADMIN.',
            ['super_admin', 'admin'] => 'SUPER ADMIN account demoted to ADMIN.',
            default => 'Account role updated.',
        };

        return back()->with('success', $message);
    }

    public function toggleStatus(Request $request, User $user, AuditLogger $auditLogger): RedirectResponse
    {
        abort_if($user->id === $request->user()->id, 403, 'You cannot deactivate your own account.');

        $newStatus = DB::transaction(function () use ($user, $auditLogger): bool {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $previous = $lockedUser->is_active;

            if ($lockedUser->isSuperAdmin() && $previous) {
                $activeSuperAdmins = User::query()
                    ->where('role', 'super_admin')
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->count();
                abort_if($activeSuperAdmins <= 1, 422, 'The last active Super Admin cannot be deactivated.');
            }

            $lockedUser->is_active = ! $previous;
            if ($previous) {
                $lockedUser->setRememberToken(Str::random(60));
            }
            $lockedUser->saveOrFail();
            if ($previous && Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $lockedUser->id)->delete();
            }
            $auditLogger->record('user.status_changed', $lockedUser, [
                'previous_status' => $previous ? 'active' : 'inactive',
                'new_status' => $lockedUser->is_active ? 'active' : 'inactive',
            ]);

            return $lockedUser->is_active;
        });

        return back()->with('success', 'Account status updated.');
    }

    public function resetPassword(Request $request, User $user, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);
        abort_if($user->id === $request->user()->id, 403, 'Use My Profile to change your own password.');

        DB::transaction(function () use ($user, $data, $auditLogger): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $lockedUser->password = $data['password'];
            $lockedUser->setRememberToken(Str::random(60));
            $lockedUser->saveOrFail();

            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $lockedUser->id)->delete();
            }
            $auditLogger->record('user.credentials_reset', $lockedUser);
        });

        return back()->with('success', 'The account password has been reset. The user must sign in again.');
    }
}
