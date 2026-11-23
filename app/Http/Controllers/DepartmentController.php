<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DepartmentController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);
        $departments = Department::with('head')->withCount('employees')->orderBy('name');
        if ($request->user()->isOfficeHead()) {
            $departments->where('head_user_id', $request->user()->id);
        }
        if (filled($filters['q'] ?? null)) {
            $search = trim($filters['q']);
            $departments->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")
                ->orWhereHas('head', fn ($head) => $head
                    ->where('role', 'office_head')
                    ->where('is_active', true)
                    ->where('name', 'like', "%{$search}%")
                    ->whereHas('employee', fn ($employee) => $employee
                        ->whereColumn('employees.department_id', 'departments.id')
                        ->where('employment_status', 'Active')
                        ->whereNull('archived_at')
                        ->whereHas('position', fn ($position) => $position
                            ->where('is_office_head_designation', true)
                            ->whereColumn('positions.department_id', 'employees.department_id')))));
        }
        if (isset($filters['status'])) {
            $departments->where('is_active', $filters['status'] === 'active');
        }

        $assignedDepartmentIds = Department::query()
            ->whereNotNull('head_user_id')
            ->get(['id', 'head_user_id'])
            ->groupBy('head_user_id')
            ->map(fn ($assignedDepartments) => $assignedDepartments->pluck('id')->map(fn ($id) => (int) $id));
        $verifiedHeads = $this->verifiedOfficeHeads();
        $departmentPage = $departments->paginate(15)->withQueryString();
        $departmentPage->getCollection()->each(function (Department $department) use ($assignedDepartmentIds, $verifiedHeads): void {
            $eligibleHeads = $verifiedHeads
                ->filter(function (User $head) use ($assignedDepartmentIds, $department): bool {
                    if ((int) $head->employee->department_id !== (int) $department->id) {
                        return false;
                    }

                    return $assignedDepartmentIds->get($head->id, collect())
                        ->every(fn (int $assignedDepartmentId) => $assignedDepartmentId === (int) $department->id);
                })
                ->values();

            $department->setRelation('eligibleOfficeHeads', $eligibleHeads);
            $department->setRelation(
                'verifiedHead',
                $eligibleHeads->first(fn (User $head) => (int) $head->id === (int) $department->head_user_id)
            );
        });

        return view('departments.index', [
            'departments' => $departmentPage,
        ]);
    }

    public function store(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150', 'unique:departments,name'],
            'code' => ['nullable', 'string', 'max:20', 'unique:departments,code'],
            'head_user_id' => ['prohibited'],
        ]);
        $department = Department::create($data);
        $auditLogger->record('department.created', $department);

        return back()->with('success', 'Department created.');
    }

    public function update(Request $request, Department $department, AuditLogger $auditLogger): RedirectResponse
    {
        $eligibleHeadIds = $this->eligibleHeadIdsFor($department);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('departments')->ignore($department->id)],
            'code' => ['nullable', 'string', 'max:20', Rule::unique('departments')->ignore($department->id)],
            'head_user_id' => ['nullable', 'integer', Rule::in($eligibleHeadIds)],
            'is_active' => ['required', 'boolean'],
        ], [
            'head_user_id.in' => 'Choose an active Office Head with a verified position in this department who is not assigned elsewhere.',
        ]);

        DB::transaction(function () use ($data, $department, $auditLogger): void {
            $department = Department::query()->lockForUpdate()->findOrFail($department->id);
            $headUserId = $data['head_user_id'] ?? null;

            if ($headUserId) {
                $head = User::query()->lockForUpdate()->findOrFail($headUserId);
                if (! $this->isVerifiedHeadForDepartment($head, $department)) {
                    throw ValidationException::withMessages([
                        'head_user_id' => 'Choose an active Office Head with a verified position in this department.',
                    ]);
                }

                if (Department::query()
                    ->where('head_user_id', $head->id)
                    ->whereKeyNot($department->id)
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'head_user_id' => 'This Office Head is already assigned to another department.',
                    ]);
                }
            }

            $previousHeadUserId = $department->head_user_id;
            $department->update($data);
            $auditLogger->record('department.updated', $department);

            if ((int) $previousHeadUserId !== (int) $department->head_user_id) {
                $auditLogger->record(
                    $department->head_user_id
                        ? 'department.office_head_assigned'
                        : 'department.office_head_assignment_revoked',
                    $department,
                    [
                        'previous_head_user_id' => $previousHeadUserId,
                        'new_head_user_id' => $department->head_user_id,
                    ]
                );
            }
        });

        return back()->with('success', 'Department updated.');
    }

    private function verifiedOfficeHeads(): Collection
    {
        return User::query()
            ->where('role', 'office_head')
            ->where('is_active', true)
            ->whereHas('employee', fn ($employee) => $employee
                ->where('employment_status', 'Active')
                ->whereNull('archived_at')
                ->whereHas('position', fn ($position) => $position
                    ->where('is_office_head_designation', true)
                    ->whereColumn('positions.department_id', 'employees.department_id')))
            ->with('employee.position')
            ->orderBy('name')
            ->get();
    }

    private function eligibleHeadIdsFor(Department $department): array
    {
        $assignedElsewhere = Department::query()
            ->whereNotNull('head_user_id')
            ->whereKeyNot($department->id)
            ->pluck('head_user_id');

        return $this->verifiedOfficeHeads()
            ->filter(fn (User $head) => (int) $head->employee->department_id === (int) $department->id
                && ! $assignedElsewhere->contains($head->id))
            ->modelKeys();
    }

    private function isVerifiedHeadForDepartment(User $head, Department $department): bool
    {
        $employee = $head->employee()->with('position')->lockForUpdate()->first();

        return $head->is_active
            && $head->isOfficeHead()
            && $employee
            && $employee->employment_status === 'Active'
            && ! $employee->archived_at
            && (int) $employee->department_id === (int) $department->id
            && $employee->position?->is_office_head_designation
            && (int) $employee->position->department_id === (int) $department->id;
    }
}
