<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        return view('users.index', [
            'users' => User::with('employee.department')->orderBy('name')->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('users.form', [
            'user' => new User(['is_active' => true]),
            'employees' => Employee::whereNull('user_id')->whereNull('archived_at')->orderBy('last_name')->get(),
        ]);
    }

    public function store(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $this->validated($request);
        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'role' => $data['role'],
                'is_active' => $data['is_active'] ?? true,
                'can_final_approve' => $data['role'] === 'admin' && ($data['can_final_approve'] ?? false),
                'password' => $data['password'],
            ]);
            $this->assignEmployee($user, $data['employee_id'] ?? null);

            return $user;
        });
        $auditLogger->record('user.created', $user, ['role' => $user->role]);

        return redirect()->route('users.index')->with('success', 'User account created.');
    }

    public function edit(User $user): View
    {
        return view('users.form', [
            'user' => $user->load('employee'),
            'employees' => Employee::whereNull('archived_at')
                ->where(function ($query) use ($user) {
                    $query->whereNull('user_id')->orWhere('user_id', $user->id);
                })
                ->orderBy('last_name')
                ->get(),
        ]);
    }

    public function update(Request $request, User $user, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $this->validated($request, $user);
        if ($user->id === $request->user()->id && ! ($data['is_active'] ?? true)) {
            return back()->withErrors(['is_active' => 'You cannot deactivate your own account.'])->withInput();
        }

        DB::transaction(function () use ($user, $data) {
            $user->fill([
                'name' => $data['name'],
                'email' => $data['email'],
                'role' => $data['role'],
                'is_active' => $data['is_active'] ?? true,
                'can_final_approve' => $data['role'] === 'admin' && ($data['can_final_approve'] ?? false),
            ]);
            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }
            $user->save();
            $this->assignEmployee($user, $data['employee_id'] ?? null);
        });
        $auditLogger->record('user.updated', $user, ['role' => $user->role, 'is_active' => $user->is_active]);

        return redirect()->route('users.index')->with('success', 'User account updated.');
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user?->id)],
            'role' => ['required', Rule::in(['admin', 'hrmo', 'office_head', 'employee'])],
            'can_final_approve' => ['nullable', 'boolean'],
            'employee_id' => [
                Rule::requiredIf($request->input('role') === 'employee'),
                'nullable',
                'integer',
                Rule::exists('employees', 'id')->whereNull('deleted_at')->whereNull('archived_at'),
            ],
            'is_active' => ['nullable', 'boolean'],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:12', 'confirmed'],
        ]);
        if (! empty($data['employee_id']) && Employee::whereKey($data['employee_id'])
            ->whereNotNull('user_id')->where('user_id', '!=', $user?->id)->exists()) {
            throw ValidationException::withMessages(['employee_id' => 'This employee record is already linked to another account.']);
        }

        return $data;
    }

    private function assignEmployee(User $user, ?int $employeeId): void
    {
        Employee::where('user_id', $user->id)->update(['user_id' => null]);
        if ($employeeId) {
            Employee::whereKey($employeeId)->update(['user_id' => $user->id]);
        }
    }
}
