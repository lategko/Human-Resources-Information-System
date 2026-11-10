<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\DeductionRecord;
use App\Models\Department;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DeductionController extends Controller
{
    public function index(Request $request): View
    {
        $query = AttendanceRecord::with(['employee.department', 'deductionRecord'])
            ->where(function ($records) {
                $records->where('late_minutes', '>', 0)->orWhere('undertime_minutes', '>', 0)->orWhere('status', 'Absent');
            });
        if ($request->filled('month')) {
            $date = $request->date('month');
            $query->whereYear('work_date', $date->year)->whereMonth('work_date', $date->month);
        } else {
            $query->whereBetween('work_date', [now()->startOfMonth()->toDateString(), today()->toDateString()]);
        }
        if ($request->user()->isOfficeHead()) {
            $query->whereHas('employee.department', fn ($department) => $department->where('head_user_id', $request->user()->id));
        } elseif ($request->filled('department_id')) {
            $query->whereHas('employee', fn ($employee) => $employee->where('department_id', $request->integer('department_id')));
        }

        return view('deductions.index', [
            'records' => $query->latest('work_date')->paginate(25)->withQueryString(),
            'departments' => Department::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function review(Request $request, AttendanceRecord $attendance, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless($request->user()->canManageHr() || $request->user()->isAdministrator(), 403);
        $data = $request->validate([
            'estimated_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'calculation_breakdown' => ['required', 'string', 'max:3000'],
            'remarks' => ['required', 'string', 'max:2000'],
        ]);
        DB::transaction(function () use ($attendance, $data, $request) {
            $record = DeductionRecord::where('attendance_record_id', $attendance->id)->lockForUpdate()->first();
            if (! $record) {
                $record = new DeductionRecord([
                    'employee_id' => $attendance->employee_id,
                    'attendance_record_id' => $attendance->id,
                    'effective_date' => $attendance->work_date,
                    'reason' => 'Attendance-related',
                ]);
            }
            $record->estimated_amount = $data['estimated_amount'] ?? null;
            $record->calculation_breakdown = [
                'reviewer_entered_breakdown' => $data['calculation_breakdown'],
                'late_minutes' => $attendance->late_minutes,
                'undertime_minutes' => $attendance->undertime_minutes,
                'attendance_status' => $attendance->status,
            ];
            $record->review_status = 'Reviewed estimate';
            $record->reviewed_by = $request->user()->id;
            $record->reviewed_at = now();
            $record->remarks = $data['remarks'];
            $record->save();
        });
        $auditLogger->record('deduction.estimate_reviewed', $attendance, ['amount' => $data['estimated_amount'] ?? null]);

        return back()->with('success', 'Estimated deduction review recorded. No payroll deduction was made.');
    }

    public function approve(Request $request, DeductionRecord $deduction, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless($request->user()->isAdministrator(), 403);
        $data = $request->validate([
            'approved_amount' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'remarks' => ['required', 'string', 'max:2000'],
        ]);
        DB::transaction(function () use ($deduction, $data, $request) {
            $deduction = DeductionRecord::query()->lockForUpdate()->findOrFail($deduction->id);
            abort_unless($deduction->review_status === 'Reviewed estimate', 409, 'Only a reviewed estimate may be approved.');
            $deduction->update([
                'approved_amount' => $data['approved_amount'],
                'review_status' => 'Approved for payroll review',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
                'approval_remarks' => $data['remarks'],
            ]);
        });
        $auditLogger->record('deduction.approved_for_payroll_review', $deduction, ['amount' => $data['approved_amount']]);

        return back()->with('success', 'Attendance deduction estimate marked approved for payroll review. No salary was automatically deducted.');
    }
}
