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
    public function index(): View
    {
        return view('departments.index', [
            'departments' => Department::with('head')->withCount('employees')->orderBy('name')->paginate(15),
            'heads' => User::where('role', 'office_head')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150', 'unique:departments,name'],
            'code' => ['nullable', 'string', 'max:20', 'unique:departments,code'],
            'head_user_id' => ['nullable', Rule::exists('users', 'id')->where('role', 'office_head')],
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
            'head_user_id' => ['nullable', Rule::exists('users', 'id')->where('role', 'office_head')],
            'is_active' => ['required', 'boolean'],
        ]);
        $department->update($data);
        $auditLogger->record('department.updated', $department);

        return back()->with('success', 'Department updated.');
    }
}
