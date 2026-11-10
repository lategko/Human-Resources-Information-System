<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Position;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PositionController extends Controller
{
    public function index(): View
    {
        return view('positions.index', [
            'positions' => Position::with('department')->withCount('employees')->orderBy('title')->paginate(20),
            'departments' => Department::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'salary_grade' => ['nullable', 'string', 'max:10'],
        ]);
        $position = Position::create($data);
        $auditLogger->record('position.created', $position);

        return back()->with('success', 'Position created.');
    }

    public function update(Request $request, Position $position, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'salary_grade' => ['nullable', 'string', 'max:10'],
            'is_active' => ['required', 'boolean'],
        ]);
        $position->update($data);
        $auditLogger->record('position.updated', $position);

        return back()->with('success', 'Position updated.');
    }
}
