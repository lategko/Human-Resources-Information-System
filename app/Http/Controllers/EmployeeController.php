<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class EmployeeController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Employee::class);
        $query = Employee::with(['department', 'position'])->whereNull('archived_at');
        if ($request->user()->isOfficeHead()) {
            $query->whereHas('department', fn ($department) => $department->where('head_user_id', $request->user()->id));
        }
        if ($request->filled('q')) {
            $search = $request->string('q')->toString();
            $query->where(fn ($employees) => $employees
                ->where('employee_no', 'like', "%{$search}%")
                ->orWhere('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%"));
        }
        if ($request->filled('department_id')) {
            $query->where('department_id', $request->integer('department_id'));
        }
        if ($request->filled('employment_status')) {
            $query->where('employment_status', $request->string('employment_status'));
        }

        return view('employees.index', [
            'employees' => $query->orderBy('last_name')->paginate(15)->withQueryString(),
            'departments' => Department::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Employee::class);

        return view('employees.form', [
            'employee' => new Employee(),
            'departments' => Department::where('is_active', true)->orderBy('name')->get(),
            'positions' => Position::where('is_active', true)->orderBy('title')->get(),
        ]);
    }

    public function store(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $this->authorize('create', Employee::class);
        $data = $this->validated($request);
        $this->assertPositionDepartment($data);
        $employee = Employee::create($data);
        $auditLogger->record('employee.created', $employee);

        return redirect()->route('employees.show', $employee)->with('success', 'Employee record created.');
    }

    public function show(Employee $employee): View
    {
        $this->authorize('view', $employee);
        $employee->load(['department', 'position', 'leaveCredits.leaveType']);

        return view('employees.show', compact('employee'));
    }

    public function editPersonal(Request $request): View
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 403);
        $this->authorize('updatePersonal', $employee);

        return view('employees.personal-edit', compact('employee'));
    }

    public function updatePersonal(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 403);
        $this->authorize('updatePersonal', $employee);
        $data = $request->validate([
            'contact_number' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'emergency_contact_name' => ['nullable', 'string', 'max:150'],
            'emergency_contact_number' => ['nullable', 'string', 'max:40'],
        ]);
        $employee->update($data);
        $auditLogger->record('employee.personal_information_updated', $employee, ['fields' => array_keys($data)]);

        return redirect()->route('employees.show', $employee)->with('success', 'Your permitted contact information has been updated.');
    }

    public function edit(Employee $employee): View
    {
        $this->authorize('update', $employee);

        return view('employees.form', [
            'employee' => $employee,
            'departments' => Department::where('is_active', true)->orderBy('name')->get(),
            'positions' => Position::where('is_active', true)->orderBy('title')->get(),
        ]);
    }

    public function update(Request $request, Employee $employee, AuditLogger $auditLogger): RedirectResponse
    {
        $this->authorize('update', $employee);
        $data = $this->validated($request, $employee);
        $this->assertPositionDepartment($data);
        $employee->update($data);
        $auditLogger->record('employee.updated', $employee);

        return redirect()->route('employees.show', $employee)->with('success', 'Employee record updated.');
    }

    public function archive(Employee $employee, AuditLogger $auditLogger): RedirectResponse
    {
        $this->authorize('archive', $employee);
        $employee->update(['employment_status' => 'Archived', 'archived_at' => today()]);
        $employee->delete();
        $auditLogger->record('employee.archived', $employee);

        return redirect()->route('employees.index')->with('success', 'Employee record archived.');
    }

    private function validated(Request $request, ?Employee $employee = null): array
    {
        return $request->validate([
            'employee_no' => ['required', 'string', 'max:50', Rule::unique('employees')->ignore($employee?->id)],
            'department_id' => ['required', 'exists:departments,id'],
            'position_id' => ['nullable', 'exists:positions,id'],
            'biometric_id' => ['nullable', 'string', 'max:80', Rule::unique('employees')->ignore($employee?->id)],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'sex' => ['nullable', 'string', 'max:20'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'civil_status' => ['nullable', 'string', 'max:40'],
            'contact_number' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'employment_status' => ['required', Rule::in(['Active', 'On Leave', 'Separated', 'Retired'])],
            'employment_type' => ['nullable', 'string', 'max:60'],
            'date_hired' => ['nullable', 'date'],
            'salary_grade' => ['nullable', 'string', 'max:10'],
            'salary_step' => ['nullable', 'integer', 'min:1', 'max:99'],
            'monthly_salary' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'government_ids' => ['nullable', 'string', 'max:1000'],
            'emergency_contact_name' => ['nullable', 'string', 'max:150'],
            'emergency_contact_number' => ['nullable', 'string', 'max:40'],
        ]);
    }

    private function assertPositionDepartment(array $data): void
    {
        if (! empty($data['position_id']) && Position::whereKey($data['position_id'])
            ->whereNotNull('department_id')->where('department_id', '!=', $data['department_id'])->exists()) {
            throw ValidationException::withMessages([
                'position_id' => 'The selected position belongs to a different department.',
            ]);
        }
    }
}
