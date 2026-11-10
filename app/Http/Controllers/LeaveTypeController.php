<?php

namespace App\Http\Controllers;

use App\Models\LeaveType;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LeaveTypeController extends Controller
{
    public function index(): View
    {
        return view('settings.leave-types', ['leaveTypes' => LeaveType::orderBy('sort_order')->get()]);
    }

    public function update(Request $request, LeaveType $leaveType, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'guidance' => ['nullable', 'string', 'max:3000'],
            'required_documents_text' => ['nullable', 'string', 'max:3000'],
            'rules_json' => ['nullable', 'json'],
            'requires_credit' => ['required', 'boolean'],
            'is_sensitive' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ]);

        $leaveType->update([
            'guidance' => $data['guidance'] ?? null,
            'required_documents' => collect(preg_split('/\r\n|\r|\n/', $data['required_documents_text'] ?? ''))
                ->map(fn ($line) => trim($line))->filter()->values()->all(),
            'rules' => isset($data['rules_json']) ? json_decode($data['rules_json'], true, 512, JSON_THROW_ON_ERROR) : null,
            'requires_credit' => $data['requires_credit'],
            'is_sensitive' => $data['is_sensitive'],
            'is_active' => $data['is_active'],
        ]);
        $auditLogger->record('leave_type.updated', $leaveType);

        return back()->with('success', 'Leave requirements and policy settings updated.');
    }
}
