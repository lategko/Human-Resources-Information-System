<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
                ->orWhereHas('head', fn ($head) => $head->where('name', 'like', "%{$search}%")));
        }
        if (isset($filters['status'])) {
            $departments->where('is_active', $filters['status'] === 'active');
        }

        $assignedHeadIds = Department::query()->whereNotNull('head_user_id')->select('head_user_id');

        return view('departments.index', [
            'departments' => $departments->paginate(15)->withQueryString(),
            'heads' => User::query()->where('role', 'office_head')
                ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $assignedHeadIds))
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150', 'unique:departments,name'],
            'code' => ['nullable', 'string', 'max:20', 'unique:departments,code'],
            'head_user_id' => ['nullable', Rule::exists('users', 'id')->where('role', 'office_head')->where('is_active', true)],
        ]);
        $department = Department::create($data);
        $auditLogger->record('department.created', $department);

        return back()->with('success', 'Department created.');
    }

    public function update(Request $request, Department $department, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('departments')->ignore($department->id)],
            'code' => ['nullable', 'string', 'max:20', Rule::unique('departments')->ignore($department->id)],
            'head_user_id' => ['nullable', Rule::exists('users', 'id')->where('role', 'office_head')->where('is_active', true)],
            'is_active' => ['required', 'boolean'],
        ]);
        $department->update($data);
        $auditLogger->record('department.updated', $department);

        return back()->with('success', 'Department updated.');
    }
}
