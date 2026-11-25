<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Services\AuditLogger;
use App\Services\EmployeeAssignmentHistoryRecorder;
use App\Services\OfficeHeadRoleAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class EmployeeController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'employment_status' => ['nullable', 'string', 'max:40'],
            'active_only' => ['nullable', 'boolean'],
            'unlinked_only' => ['nullable', 'boolean'],
            'office_head_only' => ['nullable', 'boolean'],
            'include_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'target_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        $user = $request->user();
        abort_unless(
            $user->canManageHr() || $user->isAdministrator() || $user->isOfficeHead() || $user->isMayor(),
            403
        );

        $unlinkedOnly = (bool) ($filters['unlinked_only'] ?? false);
        $officeHeadOnly = (bool) ($filters['office_head_only'] ?? false);
        abort_unless(! ($unlinkedOnly || $officeHeadOnly) || $user->isAdministrator(), 403);
        abort_unless(! isset($filters['target_user_id']) || ($unlinkedOnly && $user->isAdministrator()), 403);

        $query = Employee::query()
            ->with(['department:id,name', 'position:id,title,is_office_head_designation'])
            ->whereNull('archived_at')
            ->when($user->isOfficeHead(), fn ($employees) => $employees->whereHas(
                'department',
                fn ($department) => $department->where('head_user_id', $user->id)
            ))
            ->when(isset($filters['department_id']), fn ($employees) => $employees->where(
                'department_id',
                $filters['department_id']
            ))
            ->when(isset($filters['employment_status']), fn ($employees) => $employees->where(
                'employment_status',
                $filters['employment_status']
            ))
            ->when((bool) ($filters['active_only'] ?? false), fn ($employees) => $employees->where(
                'employment_status',
                'Active'
            ));

        if ($unlinkedOnly || $officeHeadOnly) {
            $query->where(function ($employees) use ($filters) {
                $employees->whereNull('user_id');
                if (isset($filters['include_employee_id'], $filters['target_user_id'])) {
                    $employees->orWhere(function ($selected) use ($filters) {
                        $selected->whereKey($filters['include_employee_id'])
                            ->where('user_id', $filters['target_user_id']);
                    });
                }
            });
        }

        if ($officeHeadOnly) {
            $query->whereHas('position', fn ($position) => $position->where('is_office_head_designation', true));
        }

        $search = trim($filters['q'] ?? '');
        if ($search !== '') {
            foreach (preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $term) {
                $query->where(fn ($employees) => $employees
                    ->where('employee_no', 'like', "%{$term}%")
                    ->orWhere('first_name', 'like', "%{$term}%")
                    ->orWhere('middle_name', 'like', "%{$term}%")
                    ->orWhere('last_name', 'like', "%{$term}%")
                    ->orWhere('suffix', 'like', "%{$term}%"));
            }
        }

        $page = (int) ($filters['page'] ?? 1);
        $employees = $query
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->offset(($page - 1) * 20)
            ->limit(21)
            ->get([
                'id', 'employee_no', 'first_name', 'middle_name', 'last_name', 'suffix',
                'department_id', 'position_id', 'user_id',
            ]);
        $hasMore = $employees->count() > 20;

        return response()->json([
            'results' => $employees->take(20)->map(fn (Employee $employee) => [
                'id' => $employee->id,
                'employee_no' => $employee->employee_no,
                'name' => $employee->full_name,
                'label' => $employee->employee_no.' · '.$employee->full_name,
                'department' => $employee->department?->name,
                'position' => $employee->position?->title,
            ])->values(),
            'has_more' => $hasMore,
            'page' => $page,
        ]);
    }

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
        $sortable = [
            'employee_no' => 'employee_no',
            'last_name' => 'last_name',
            'employment_type' => 'employment_type',
            'employment_status' => 'employment_status',
            'date_hired' => 'date_hired',
        ];
        $sort = $request->string('sort')->toString();
        $sort = array_key_exists($sort, $sortable) ? $sort : 'last_name';
        $direction = $request->input('direction') === 'desc' ? 'desc' : 'asc';

        return view('employees.index', [
            'employees' => $query->orderBy($sortable[$sort], $direction)->orderBy('id')->paginate(15)->withQueryString(),
            'departments' => Department::where('is_active', true)
                ->when($request->user()->isOfficeHead(), fn ($departments) => $departments->where('head_user_id', $request->user()->id))
                ->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Employee::class);

        return view('employees.form', [
            'employee' => new Employee,
            'departments' => Department::where('is_active', true)->orderBy('name')->get(),
            'positions' => Position::where('is_active', true)->orderBy('title')->get(),
        ]);
    }

    public function store(
        Request $request,
        AuditLogger $auditLogger,
        EmployeeAssignmentHistoryRecorder $historyRecorder
    ): RedirectResponse {
        $this->authorize('create', Employee::class);
        $data = $this->validated($request);
        $this->assertPositionDepartment($data);
        unset($data['assignment_effective_date'], $data['assignment_change_reason']);
        $employee = DB::transaction(function () use ($data, $request, $auditLogger, $historyRecorder) {
            $employee = Employee::create($data);
            $historyRecorder->recordInitial($employee, $request->user()->id);
            $auditLogger->record('employee.created', $employee);

            return $employee;
        });

        return redirect()->route('employees.show', $employee)->with('success', "Employee record for {$employee->full_name} has been created.");
    }

    public function show(Request $request, Employee $employee): View
    {
        $this->authorize('view', $employee);
        $employee->load(['department', 'position', 'leaveCredits.leaveType', 'employmentHistories', 'serviceProfile']);
        if ($request->user()->canManageHr()) {
            $employee->load('assignmentHistories.changedBy');
        }

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
            'departments' => Department::query()->where('is_active', true)
                ->when($employee->department_id, fn ($query) => $query->orWhereKey($employee->department_id))
                ->orderBy('name')->get(),
            'positions' => Position::query()->where('is_active', true)
                ->when($employee->position_id, fn ($query) => $query->orWhereKey($employee->position_id))
                ->orderBy('title')->get(),
        ]);
    }

    public function update(
        Request $request,
        Employee $employee,
        AuditLogger $auditLogger,
        EmployeeAssignmentHistoryRecorder $historyRecorder,
        OfficeHeadRoleAssignmentService $officeHeadRoleAssignmentService
    ): RedirectResponse {
        $this->authorize('update', $employee);
        $data = $this->validated($request, $employee);
        $this->assertPositionDepartment($data, $employee);
        $employee->loadMissing('position');
        $previousPositionId = $employee->position_id;
        $previousDepartmentId = $employee->department_id;
        $previousPositionWasOfficeHeadDesignation = (bool) $employee->position?->is_office_head_designation;
        $previousAssignment = $historyRecorder->snapshot($employee);
        $effectiveDate = $data['assignment_effective_date'] ?? today()->toDateString();
        $reason = $data['assignment_change_reason'] ?? null;
        unset($data['assignment_effective_date'], $data['assignment_change_reason']);
        DB::transaction(function () use (
            $data,
            $employee,
            $request,
            $auditLogger,
            $historyRecorder,
            $officeHeadRoleAssignmentService,
            $previousPositionId,
            $previousDepartmentId,
            $previousPositionWasOfficeHeadDesignation,
            $previousAssignment,
            $effectiveDate,
            $reason
        ): void {
            $employee->update($data);
            $employee->refresh();
            $employee->load('position');
            $assignmentChanged = $previousPositionId !== $employee->position_id
                || $previousDepartmentId !== $employee->department_id;
            if ($previousPositionWasOfficeHeadDesignation && $previousPositionId !== null && $assignmentChanged) {
                $officeHeadRoleAssignmentService->revokeEmployeeFromPosition(
                    $employee->id,
                    $previousPositionId,
                    $previousDepartmentId,
                    $request->user()
                );
            }
            if ($employee->position?->is_office_head_designation) {
                $officeHeadRoleAssignmentService->synchronizeVerifiedPosition(
                    $employee->position,
                    $request->user()
                );
            }
            $historyRecorder->recordChange(
                $employee,
                $previousAssignment,
                $request->user()->id,
                $effectiveDate,
                $reason
            );
            $auditLogger->record('employee.updated', $employee);
        });

        return redirect()->route('employees.show', $employee)->with('success', "Employee record for {$employee->full_name} has been updated.");
    }

    public function archive(Employee $employee, AuditLogger $auditLogger): RedirectResponse
    {
        $this->authorize('archive', $employee);
        $employee->update(['employment_status' => 'Archived', 'archived_at' => today()]);
        $employee->delete();
        $auditLogger->record('employee.archived', $employee);

        return redirect()->route('employees.index')->with('success', "Employee record for {$employee->full_name} has been archived and removed from active personnel.");
    }

    private function validated(Request $request, ?Employee $employee = null): array
    {
        return $request->validate([
            'employee_no' => ['required', 'string', 'max:50', Rule::unique('employees')->ignore($employee?->id)],
            'department_id' => ['required', 'exists:departments,id'],
            'position_id' => ['nullable', 'exists:positions,id'],
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
            'assignment_effective_date' => ['nullable', 'date', 'before_or_equal:today'],
            'assignment_change_reason' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function assertPositionDepartment(array $data, ?Employee $employee = null): void
    {
        $department = Department::findOrFail($data['department_id']);
        if (! $department->is_active && (int) $department->id !== (int) ($employee?->department_id ?? 0)) {
            throw ValidationException::withMessages([
                'department_id' => 'New employee assignments must use an active department.',
            ]);
        }

        if (empty($data['position_id'])) {
            return;
        }

        $position = Position::findOrFail($data['position_id']);
        if (! $position->is_active && (int) $position->id !== (int) ($employee?->position_id ?? 0)) {
            throw ValidationException::withMessages([
                'position_id' => 'New employee assignments must use an active position.',
            ]);
        }
        if ($position->department_id !== null && (int) $position->department_id !== (int) $data['department_id']) {
            throw ValidationException::withMessages([
                'position_id' => 'The selected position belongs to a different department.',
            ]);
        }
    }
}
