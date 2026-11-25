<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Position;
use App\Services\AuditLogger;
use App\Services\OfficeHeadRoleAssignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PositionController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'salary_grade' => ['nullable', 'string', 'max:10'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);
        $positions = Position::with('department')->withCount('employees');
        if (filled($filters['q'] ?? null)) {
            $search = trim($filters['q']);
            $positions->where(fn ($query) => $query
                ->where('title', 'like', "%{$search}%")
                ->orWhereHas('department', fn ($department) => $department->where('name', 'like', "%{$search}%")));
        }
        if (isset($filters['department_id'])) {
            $positions->where('department_id', $filters['department_id']);
        }
        if (filled($filters['salary_grade'] ?? null)) {
            $positions->where('salary_grade', $filters['salary_grade']);
        }
        if (isset($filters['status'])) {
            $positions->where('is_active', $filters['status'] === 'active');
        }
        $positions = $positions->orderBy('title')->paginate(20)->withQueryString();

        return view('positions.index', [
            'positions' => $positions,
            'departments' => Department::orderBy('name')->get(),
            'activeDepartments' => Department::where('is_active', true)->orderBy('name')->get(),
            'salaryGrades' => Position::query()->whereNotNull('salary_grade')->distinct()->orderBy('salary_grade')->pluck('salary_grade'),
        ]);
    }

    public function store(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $this->validated($request);
        $position = Position::create($data);
        $auditLogger->record('position.created', $position);

        return back()->with('success', "The {$position->title} position has been added to the HRIS organization directory.");
    }

    public function update(
        Request $request,
        Position $position,
        AuditLogger $auditLogger,
        OfficeHeadRoleAssignmentService $officeHeadRoleAssignmentService
    ): RedirectResponse {
        $data = $this->validated($request, $position);
        $wasOfficeHeadDesignation = $position->is_office_head_designation;
        DB::transaction(function () use (
            $data,
            $position,
            $wasOfficeHeadDesignation,
            $request,
            $auditLogger,
            $officeHeadRoleAssignmentService
        ): void {
            $position->update($data);
            $position->refresh();
            if ($position->is_office_head_designation) {
                $officeHeadRoleAssignmentService->synchronizeVerifiedPosition($position, $request->user());
            } elseif ($wasOfficeHeadDesignation) {
                $officeHeadRoleAssignmentService->revokePosition($position, $request->user());
            }
            $auditLogger->record('position.updated', $position);
        });

        return back()->with('success', "The {$position->title} position details and active status have been updated.");
    }

    private function validated(Request $request, ?Position $position = null): array
    {
        $uniqueTitle = Rule::unique('positions', 'title')
            ->where(fn ($query) => $query->where(
                'department_id',
                $request->input('department_id', $position?->department_id)
            ));
        if ($position) {
            $uniqueTitle->ignore($position->id);
        }

        $rules = [
            'title' => ['required', 'string', 'max:150', $uniqueTitle],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'salary_grade' => ['nullable', 'string', 'max:10'],
            'is_office_head_designation' => ['nullable', 'boolean'],
        ];
        if ($position) {
            $rules['is_active'] = ['required', 'boolean'];
        }

        $data = $request->validate($rules);

        if (($data['is_office_head_designation'] ?? false) && ! ($data['department_id'] ?? $position?->department_id)) {
            throw ValidationException::withMessages([
                'department_id' => 'An Office Head designation must be assigned to a specific department.',
            ]);
        }

        if (! empty($data['department_id'])) {
            $department = Department::findOrFail($data['department_id']);
            if (! $department->is_active && (int) $department->id !== (int) ($position?->department_id ?? 0)) {
                throw ValidationException::withMessages([
                    'department_id' => 'New positions must be assigned to an active department.',
                ]);
            }
        }

        return $data;
    }
}
