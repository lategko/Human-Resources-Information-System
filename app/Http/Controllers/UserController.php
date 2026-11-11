<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Department;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        return view('users.index', [
            'users' => User::with(['employee.department', 'departmentHead'])->orderBy('name')->paginate(20),
        ]);
    }

    public function create(Request $request): View
    {
        return view('users.form', [
            'user' => new User(['is_active' => true]),
            'employees' => Employee::whereNull('user_id')->whereNull('archived_at')->orderBy('last_name')->get(),
            'departments' => Department::where('is_active', true)->orderBy('name')->get(),
            'allowedRoles' => $this->allowedRoles($request->user()),
        ]);
    }

    public function store(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $this->validated($request);
        $user = DB::transaction(function () use ($data, $request) {
            $user = User::create([
                'name' => $this->accountName($data),
                'email' => $data['email'],
                'role' => $data['role'],
                'first_name' => $data['role'] === 'employee' ? null : ($data['first_name'] ?? null),
                'middle_name' => $data['role'] === 'employee' ? null : ($data['middle_name'] ?? null),
                'last_name' => $data['role'] === 'employee' ? null : ($data['last_name'] ?? null),
                'suffix' => $data['role'] === 'employee' ? null : ($data['suffix'] ?? null),
                'is_active' => $data['is_active'] ?? true,
                'can_final_approve' => $data['role'] === 'admin' && $request->user()->isSuperAdmin() && ($data['can_final_approve'] ?? false),
                'password' => $data['password'],
            ]);
            $this->assignEmployee($user, $data['role'] === 'employee' ? $data['employee_id'] : null);
            $this->assignOfficeHeadDepartment($user, $data['role'] === 'office_head' ? $data['department_id'] : null);

            return $user;
        });
        $auditLogger->record('user.created', $user, ['role' => $user->role]);

        return redirect()->route('users.index')->with('success', 'User account created.');
    }

    public function edit(Request $request, User $user): View
    {
        $this->authorizeAccountManagement($request->user(), $user);

        return view('users.form', [
            'user' => $user->load('employee'),
            'employees' => Employee::whereNull('archived_at')
                ->where(function ($query) use ($user) {
                    $query->whereNull('user_id')->orWhere('user_id', $user->id);
                })
                ->orderBy('last_name')
                ->get(),
            'departments' => Department::where('is_active', true)
                ->orWhereHas('head', fn ($query) => $query->whereKey($user->id))
                ->orderBy('name')
                ->get(),
            'assignedDepartmentId' => Department::where('head_user_id', $user->id)->value('id'),
            'allowedRoles' => $this->allowedRoles($request->user()),
        ]);
    }

    public function update(Request $request, User $user, AuditLogger $auditLogger): RedirectResponse
    {
        $this->authorizeAccountManagement($request->user(), $user);
        $data = $this->validated($request, $user);
        if ($user->id === $request->user()->id && $user->role !== $data['role']) {
            abort(403, 'You cannot change your own role.');
        }
        if ($user->id === $request->user()->id && ! ($data['is_active'] ?? true)) {
            return back()->withErrors(['is_active' => 'You cannot deactivate your own account.'])->withInput();
        }
        $previousRole = $user->role;
        $previousEffectiveRole = $user->canFinalApprove() ? 'mayor' : $user->role;
        $previousStatus = $user->is_active;
        $passwordReset = filled($data['password'] ?? null);

        DB::transaction(function () use ($user, $data, $request, $passwordReset) {
            $user->fill([
                'name' => $this->accountName($data),
                'email' => $data['email'],
                'role' => $data['role'],
                'first_name' => $data['role'] === 'employee' ? null : ($data['first_name'] ?? $user->first_name),
                'middle_name' => $data['role'] === 'employee' ? null : ($data['middle_name'] ?? null),
                'last_name' => $data['role'] === 'employee' ? null : ($data['last_name'] ?? $user->last_name),
                'suffix' => $data['role'] === 'employee' ? null : ($data['suffix'] ?? null),
                'is_active' => $data['is_active'] ?? true,
                'can_final_approve' => $data['role'] === 'admin' && $request->user()->isSuperAdmin() && ($data['can_final_approve'] ?? false),
            ]);
            if (! empty($data['password'])) {
                $user->password = $data['password'];
                $user->setRememberToken(Str::random(60));
            }
            $user->save();
            if ($passwordReset && Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
            $this->assignEmployee($user, $data['role'] === 'employee' ? $data['employee_id'] : null);
            $this->assignOfficeHeadDepartment($user, $data['role'] === 'office_head' ? $data['department_id'] : null);
        });
        $newEffectiveRole = $user->canFinalApprove() ? 'mayor' : $user->role;
        if ($previousEffectiveRole !== $newEffectiveRole) {
            $auditLogger->record('user.role_changed', $user, [
                'previous_role' => $previousEffectiveRole,
                'new_role' => $newEffectiveRole,
                'previous_stored_role' => $previousRole,
                'new_stored_role' => $user->role,
            ]);
        }
        if ($previousStatus !== $user->is_active) {
            $auditLogger->record('user.status_changed', $user, [
                'previous_status' => $previousStatus ? 'active' : 'inactive',
                'new_status' => $user->is_active ? 'active' : 'inactive',
            ]);
        }
        if ($passwordReset) {
            $auditLogger->record('user.credentials_reset', $user);
        }
        $auditLogger->record('user.updated', $user, ['role' => $user->role, 'is_active' => $user->is_active]);

        return redirect()->route('users.index')->with('success', 'User account updated.');
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $employeeRole = $request->input('role') === 'employee';
        $namePartsRequired = ! $user && ! $employeeRole ? 'required' : 'nullable';
        $data = $request->validate([
            'name' => [$employeeRole ? 'nullable' : 'required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user?->id)],
            'role' => ['required', Rule::in($this->allowedRoles($request->user()))],
            'first_name' => [$employeeRole ? 'nullable' : $namePartsRequired, 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => [$employeeRole ? 'nullable' : $namePartsRequired, 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'can_final_approve' => [
                'nullable',
                'boolean',
                Rule::prohibitedIf(! $request->user()->isSuperAdmin()),
            ],
            'employee_id' => [
                Rule::requiredIf($employeeRole),
                'nullable',
                'integer',
                Rule::exists('employees', 'id')->whereNull('deleted_at')->whereNull('archived_at'),
            ],
            'department_id' => [
                Rule::prohibitedIf(! in_array($request->input('role'), ['office_head'], true)),
                Rule::requiredIf($request->input('role') === 'office_head'),
                'nullable',
                'integer',
                Rule::exists('departments', 'id'),
            ],
            'is_active' => ['nullable', 'boolean'],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:12', 'confirmed'],
        ]);
        if (! empty($data['employee_id']) && Employee::whereKey($data['employee_id'])
            ->whereNotNull('user_id')->where('user_id', '!=', $user?->id)->exists()) {
            throw ValidationException::withMessages(['employee_id' => 'This employee record is already linked to another account.']);
        }
        if ($employeeRole && $user && $user->employee && (int) $data['employee_id'] !== $user->employee->id) {
            throw ValidationException::withMessages([
                'employee_id' => 'An existing employee account cannot be linked to a different employee record.',
            ]);
        }
        $departmentHeadId = ! empty($data['department_id'])
            ? Department::whereKey($data['department_id'])->value('head_user_id')
            : null;
        if ($departmentHeadId && (int) $departmentHeadId !== $user?->id) {
            throw ValidationException::withMessages([
                'department_id' => 'This department already has an assigned Office Head.',
            ]);
        }

        return $data;
    }

    private function allowedRoles(User $actor): array
    {
        return $actor->isSuperAdmin()
            ? ['admin', 'hrmo', 'office_head', 'mayor', 'employee']
            : ['hrmo', 'office_head', 'employee'];
    }

    private function authorizeAccountManagement(User $actor, User $target): void
    {
        abort_if($target->isSuperAdmin(), 403, 'Super Admin accounts are managed through protected account controls.');
        abort_unless(
            $actor->isSuperAdmin() || (! $target->isAdministrator() && ! $target->isMayor()),
            403,
            'Only a Super Admin can manage ADMIN or Mayor accounts.'
        );
    }

    private function accountName(array $data): string
    {
        if ($data['role'] === 'employee') {
            return Employee::findOrFail($data['employee_id'])->full_name;
        }

        if (filled($data['first_name'] ?? null) && filled($data['last_name'] ?? null)) {
            return trim(implode(' ', array_filter([
                $data['first_name'],
                $data['middle_name'] ?? null,
                $data['last_name'],
                $data['suffix'] ?? null,
            ])));
        }

        return $data['name'];
    }

    private function assignEmployee(User $user, ?int $employeeId): void
    {
        Employee::where('user_id', $user->id)->update(['user_id' => null]);
        if ($employeeId) {
            Employee::whereKey($employeeId)->update(['user_id' => $user->id]);
        }
    }

    private function assignOfficeHeadDepartment(User $user, ?int $departmentId): void
    {
        Department::where('head_user_id', $user->id)
            ->when($departmentId, fn ($query) => $query->whereKeyNot($departmentId))
            ->update(['head_user_id' => null]);

        if ($departmentId && $user->isOfficeHead()) {
            Department::whereKey($departmentId)->update(['head_user_id' => $user->id]);
        }
    }
}
