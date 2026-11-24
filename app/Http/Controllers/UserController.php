<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\EmployeeUsernameGenerator;
use App\Services\OfficeHeadRoleAssignmentService;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        abort_if(mb_strlen($search) > 100, 422, 'The search term is too long.');

        return view('users.index', [
            'search' => $search,
            'users' => User::with(['employee.department', 'employee.position', 'departmentHead'])
                ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%")
                        ->orWhereHas('employee', fn ($employee) => $employee
                            ->where('employee_no', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%"));
                }))
                ->orderBy('name')->paginate(20)->withQueryString(),
            'unlinkedEmployees' => Employee::with(['department', 'position'])
                ->whereNull('user_id')->whereNull('archived_at')
                ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                    $query->where('employee_no', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('middle_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%");
                }))
                ->orderBy('last_name')->paginate(20, ['*'], 'unlinked_page')->withQueryString(),
        ]);
    }

    public function create(Request $request): View
    {
        $selectedEmployeeId = $request->integer('employee_id') ?: (int) old('employee_id');
        $selectedEmployee = $selectedEmployeeId
            ? Employee::with(['department', 'position'])->whereNull('user_id')->whereNull('archived_at')->find($selectedEmployeeId)
            : null;

        return view('users.form', [
            'user' => new User([
                'is_active' => true,
                'role' => $selectedEmployee
                    ? ($selectedEmployee->position?->is_office_head_designation ? 'office_head' : 'employee')
                    : null,
                'email' => $selectedEmployee?->email,
            ]),
            'selectedEmployeeId' => $selectedEmployee?->id,
            'selectedEmployee' => $selectedEmployee,
            'assignedDepartmentId' => $selectedEmployee?->department_id,
            'departments' => Department::where('is_active', true)->orderBy('name')->get(),
            'allowedRoles' => $this->allowedRoles($request->user()),
        ]);
    }

    public function store(
        Request $request,
        AuditLogger $auditLogger,
        EmployeeUsernameGenerator $usernameGenerator,
        OfficeHeadRoleAssignmentService $officeHeadRoleAssignmentService
    ): RedirectResponse {
        $data = $this->validated($request);
        $employeeAccountCreated = $data['role'] === 'employee';
        $user = DB::transaction(function () use (
            $data,
            $usernameGenerator,
            $request,
            $officeHeadRoleAssignmentService,
            $employeeAccountCreated
        ) {
            $employee = ! empty($data['employee_id']) ? Employee::findOrFail($data['employee_id']) : null;
            $user = User::create([
                'name' => $this->accountName($data),
                'username' => filled($data['username'] ?? null) ? $data['username'] : ($employee
                    ? $usernameGenerator->generateForNameParts(
                        $employee->first_name,
                        $employee->middle_name,
                        $employee->last_name,
                        $employee->suffix,
                        $employee->employee_no
                    )
                    : null),
                'email' => $data['email'] ?? null,
                'role' => $data['role'],
                'first_name' => $data['role'] === 'employee' ? null : ($data['first_name'] ?? null),
                'middle_name' => $data['role'] === 'employee' ? null : ($data['middle_name'] ?? null),
                'last_name' => $data['role'] === 'employee' ? null : ($data['last_name'] ?? null),
                'suffix' => $data['role'] === 'employee' ? null : ($data['suffix'] ?? null),
                'is_active' => $data['is_active'] ?? true,
                'can_final_approve' => $data['role'] === 'admin' && ($data['can_final_approve'] ?? false),
                'password' => $employeeAccountCreated ? Str::random(64) : $data['password'],
                'must_change_password' => $employeeAccountCreated,
                'account_setup_completed' => ! $employeeAccountCreated,
            ]);
            if (array_key_exists('employee_id', $data)) {
                $this->assignEmployee($user, $data['employee_id'] ?? null);
            }
            $this->assignOfficeHeadDepartment($user, $data['role'] === 'office_head' ? $data['department_id'] : null);
            $linkedEmployee = $user->employee()->with('position')->first();
            if ($linkedEmployee?->position?->is_office_head_designation) {
                $officeHeadRoleAssignmentService->synchronizeVerifiedPosition(
                    $linkedEmployee->position,
                    $request->user()
                );
            }

            return $user;
        });
        $user->refresh();
        $activationRequired = $user->must_change_password && ! $user->account_setup_completed;
        $auditLogger->record('user.created', $user, [
            'role' => $user->role,
            'username' => $user->username,
            'department_id' => Department::where('head_user_id', $user->id)->value('id'),
            'activation_pending' => $activationRequired && ! $user->email,
        ]);

        if ($activationRequired && $user->email) {
            try {
                $status = Password::sendResetLink(['email' => $user->email]);
            } catch (TransportExceptionInterface $exception) {
                report($exception);

                return redirect()->route('users.index')->with(
                    'warning',
                    'Employee account created, but the activation email could not be sent. Check the email address and mail service, then resend the link from account management.'
                );
            }
            if ($status === PasswordBroker::RESET_LINK_SENT) {
                $auditLogger->record('user.password_reset_link_sent', $user);

                return redirect()->route('users.index')->with('success', 'Employee account created. A secure activation link was emailed.');
            }

            return redirect()->route('users.index')->with(
                'warning',
                'Employee account created, but the activation email could not be sent. Check the email address and mail service, then resend the link from account management.'
            );
        }

        return redirect()->route('users.index')->with(
            $activationRequired ? 'warning' : 'success',
            $activationRequired
                ? 'Employee account created without an email. Add a verified contact address and send an activation link from account management.'
                : 'User account created.'
        );
    }

    public function edit(Request $request, User $user): View
    {
        $this->authorizeAccountManagement($request->user(), $user);
        $selectedEmployee = Employee::with(['department', 'position'])
            ->whereNull('archived_at')
            ->where(fn ($employees) => $employees->whereNull('user_id')->orWhere('user_id', $user->id))
            ->find(old('employee_id', $user->employee?->id));

        return view('users.form', [
            'user' => $user->load('employee'),
            'selectedEmployeeId' => $user->employee?->id,
            'selectedEmployee' => $selectedEmployee,
            'departments' => Department::where('is_active', true)
                ->orWhereHas('head', fn ($query) => $query->whereKey($user->id))
                ->orderBy('name')
                ->get(),
            'assignedDepartmentId' => Department::where('head_user_id', $user->id)->value('id'),
            'allowedRoles' => $this->allowedRoles($request->user(), $user),
        ]);
    }

    public function update(
        Request $request,
        User $user,
        AuditLogger $auditLogger,
        OfficeHeadRoleAssignmentService $officeHeadRoleAssignmentService
    ): RedirectResponse {
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
        DB::transaction(function () use ($user, $data, $previousStatus, $request, $officeHeadRoleAssignmentService) {
            $user->fill([
                'name' => $this->accountName($data),
                'username' => $data['username'] ?? $user->username,
                'email' => $data['email'] ?? null,
                'role' => $data['role'],
                'first_name' => $data['role'] === 'employee' ? null : ($data['first_name'] ?? $user->first_name),
                'middle_name' => $data['role'] === 'employee' ? null : ($data['middle_name'] ?? null),
                'last_name' => $data['role'] === 'employee' ? null : ($data['last_name'] ?? $user->last_name),
                'suffix' => $data['role'] === 'employee' ? null : ($data['suffix'] ?? null),
                'is_active' => $data['is_active'] ?? true,
                'can_final_approve' => $data['role'] === 'admin' && ($data['can_final_approve'] ?? false),
            ]);
            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }
            if ($previousStatus && ! $user->is_active) {
                $user->setRememberToken(Str::random(60));
            }
            $user->save();
            if (($previousStatus && ! $user->is_active) && Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
            if (array_key_exists('employee_id', $data)) {
                $this->assignEmployee($user, $data['employee_id'] ?? null);
            }
            $this->assignOfficeHeadDepartment($user, $data['role'] === 'office_head' ? $data['department_id'] : null);
            $linkedEmployee = $user->employee()->with('position')->first();
            if ($linkedEmployee?->position?->is_office_head_designation) {
                $officeHeadRoleAssignmentService->synchronizeVerifiedPosition(
                    $linkedEmployee->position,
                    $request->user(),
                    false
                );
            }
        });
        $user->refresh();
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
        $auditLogger->record('user.updated', $user, [
            'role' => $user->role,
            'is_active' => $user->is_active,
            'department_id' => Department::where('head_user_id', $user->id)->value('id'),
        ]);

        return redirect()->route('users.index')->with('success', 'User account updated.');
    }

    public function sendActivationLink(Request $request, User $user, AuditLogger $auditLogger): RedirectResponse
    {
        $this->authorizeAccountManagement($request->user(), $user);
        if (! $user->email) {
            return back()->withErrors(['email' => 'Add an email address before sending an account activation or password reset link.']);
        }

        try {
            $status = Password::sendResetLink(['email' => $user->email]);
        } catch (TransportExceptionInterface $exception) {
            report($exception);

            return back()->withErrors(['email' => 'The activation/reset email could not be sent. Check the mail service and try again.']);
        }
        if ($status !== PasswordBroker::RESET_LINK_SENT) {
            return back()->withErrors(['email' => __($status)]);
        }
        $auditLogger->record('user.password_reset_link_sent', $user);

        return back()->with('success', 'A secure password setup/reset link was sent to the registered email address.');
    }

    public function generateTemporaryCredential(Request $request, User $user, AuditLogger $auditLogger): Response
    {
        $this->authorizeAccountManagement($request->user(), $user);
        abort_unless($user->isEmployee() && $user->employee()->exists() && $user->is_active, 403);

        $temporaryPassword = Str::random(40);
        DB::transaction(function () use ($user, $temporaryPassword): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($lockedUser->isEmployee() && $lockedUser->employee()->exists() && $lockedUser->is_active, 403);
            $lockedUser->password = $temporaryPassword;
            $lockedUser->must_change_password = true;
            $lockedUser->account_setup_completed = false;
            $lockedUser->setRememberToken(Str::random(60));
            $lockedUser->saveOrFail();

            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $lockedUser->id)->delete();
            }
        });

        $auditLogger->record('user.temporary_credential_generated', $user, [
            'employee_id' => $user->employee->id,
        ]);

        return response()->view('users.temporary-credential', [
            'user' => $user->load('employee'),
            'temporaryPassword' => $temporaryPassword,
        ])->withHeaders([
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $request->merge([
            'username' => filled($request->input('username'))
                ? Str::lower(trim((string) $request->input('username')))
                : null,
            'email' => filled($request->input('email'))
                ? Str::lower(trim((string) $request->input('email')))
                : null,
        ]);
        $employeeRole = $request->input('role') === 'employee';
        $employeeIdentityRole = $employeeRole || $request->input('role') === 'office_head';
        $namePartsRequired = ! $user && ! $employeeIdentityRole ? 'required' : 'nullable';
        $data = $request->validate([
            'name' => [$employeeIdentityRole ? 'nullable' : 'required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'username' => [
                Rule::requiredIf(fn () => ! $employeeRole && ! filled($request->input('email'))),
                'nullable', 'string', 'min:3', 'max:100', 'regex:/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/',
                Rule::unique('users', 'username')->ignore($user?->id),
            ],
            'role' => ['required', Rule::in($this->allowedRoles($request->user(), $user))],
            'first_name' => [$employeeIdentityRole ? 'nullable' : $namePartsRequired, 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => [$employeeIdentityRole ? 'nullable' : $namePartsRequired, 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'can_final_approve' => [
                'nullable',
                'boolean',
                Rule::prohibitedIf($request->input('role') !== 'admin'),
            ],
            'employee_id' => [
                Rule::requiredIf($employeeRole || $request->input('role') === 'office_head'),
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
        ]);
        if (! $user && ! $employeeRole) {
            $data += $request->validate([
                'password' => ['required', 'string', 'min:12', 'confirmed'],
            ]);
        }
        if (! empty($data['employee_id']) && Employee::whereKey($data['employee_id'])
            ->whereNotNull('user_id')->where('user_id', '!=', $user?->id)->exists()) {
            throw ValidationException::withMessages(['employee_id' => 'This employee record is already linked to another account.']);
        }
        if (in_array($data['role'], ['employee', 'office_head'], true)
            && $user
            && $user->employee
            && (int) $data['employee_id'] !== $user->employee->id) {
            throw ValidationException::withMessages([
                'employee_id' => 'An existing employee account cannot be linked to a different employee record.',
            ]);
        }
        if ($data['role'] === 'office_head') {
            $employee = Employee::with('position')->findOrFail($data['employee_id']);
            if (! $employee->position?->is_office_head_designation
                || ! $employee->department_id
                || (int) $employee->department_id !== (int) $employee->position->department_id
                || (int) $employee->department_id !== (int) $data['department_id']) {
                throw ValidationException::withMessages([
                    'employee_id' => 'Office Head access requires an employee record with an HR-approved position designation in the selected department.',
                ]);
            }
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

    private function allowedRoles(?User $actor = null, ?User $target = null): array
    {
        return array_values(array_filter(
            User::VALID_ROLES,
            fn (string $role) => $role !== 'admin'
                || ($actor?->isAdministrator() && $target?->role === 'admin')
        ));
    }

    private function authorizeAccountManagement(User $actor, User $target): void
    {
        abort_unless($actor->isAdministrator(), 403);
    }

    private function accountName(array $data): string
    {
        if (in_array($data['role'], ['employee', 'office_head'], true)) {
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
