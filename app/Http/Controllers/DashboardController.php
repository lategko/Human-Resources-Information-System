<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveApplication;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $employee = $user->employee;
        $departmentId = $user->isOfficeHead()
            ? Department::where('head_user_id', $user->id)->value('id')
            : null;

        if ($user->isEmployee()) {
            abort_unless($employee, 403, 'Your account is not linked to an employee record.');
            $applications = $employee->leaveApplications()->with('leaveType')->latest()->limit(5)->get();
            $credits = $employee->leaveCredits()->with('leaveType')->get();
            $attendance = $employee->attendanceRecords()->whereMonth('work_date', now()->month)->get();

            return view('dashboard', [
                'role' => 'employee',
                'employee' => $employee->load('department', 'position'),
                'applications' => $applications,
                'credits' => $credits,
                'presentCount' => $attendance->whereIn('status', ['Present', 'Late'])->count(),
                'lateCount' => $attendance->where('late_minutes', '>', 0)->count(),
                'pageTitle' => 'My workspace',
            ]);
        }

        $trendRecords = AttendanceRecord::query()
            ->whereBetween('work_date', [now()->subDays(13)->toDateString(), today()->toDateString()])
            ->when($departmentId, fn ($query) => $query->whereHas('employee', fn ($employees) => $employees->where('department_id', $departmentId)))
            ->get(['work_date', 'status'])
            ->map(fn (AttendanceRecord $record) => [
                'work_date' => $record->work_date->toDateString(),
                'status' => $record->status,
            ]);
        $trend = collect(range(13, 0))->map(function (int $daysAgo) use ($trendRecords) {
            $date = now()->subDays($daysAgo)->toDateString();
            $records = $trendRecords->where('work_date', $date);

            return [
                'label' => now()->subDays($daysAgo)->format('M j'),
                'present' => $records->where('status', 'Present')->count(),
                'late' => $records->where('status', 'Late')->count(),
                'absent' => $records->where('status', 'Absent')->count(),
            ];
        });

        $employees = Employee::query()->whereNull('archived_at');
        $attendance = AttendanceRecord::query()->whereDate('work_date', today());
        $leaves = LeaveApplication::query();
        if ($departmentId) {
            $employees->where('department_id', $departmentId);
            $attendance->whereHas('employee', fn ($query) => $query->where('department_id', $departmentId));
            $leaves->whereHas('employee', fn ($query) => $query->where('department_id', $departmentId));
        }

        return view('dashboard', [
            'role' => $user->role,
            'employeeCount' => $employees->count(),
            'departmentCount' => $departmentId ? 1 : Department::where('is_active', true)->count(),
            'presentCount' => (clone $attendance)->whereIn('status', ['Present', 'Late'])->count(),
            'absentCount' => (clone $attendance)->where('status', 'Absent')->count(),
            'lateCount' => (clone $attendance)->where('late_minutes', '>', 0)->count(),
            'pendingCount' => (clone $leaves)->whereIn('status', ['Submitted', 'Under Office Head Review', 'Endorsed', 'Under HRMO Verification', 'Pending Final Approval'])->count(),
            'leaveTodayCount' => LeaveApplication::where('status', 'Approved')
                ->whereDate('start_date', '<=', today())->whereDate('end_date', '>=', today())
                ->when($departmentId, fn ($query) => $query->whereHas('employee', fn ($employees) => $employees->where('department_id', $departmentId)))
                ->count(),
            'recentApplications' => (clone $leaves)->with('employee.department', 'leaveType')->latest()->limit(6)->get(),
            'recentAttendance' => (clone $attendance)->with('employee.department')->latest()->limit(6)->get(),
            'chartData' => [
                'labels' => $trend->pluck('label'),
                'present' => $trend->pluck('present'),
                'late' => $trend->pluck('late'),
                'absent' => $trend->pluck('absent'),
            ],
            'pageTitle' => $user->isOfficeHead() ? 'Department overview' : 'HR overview',
        ]);
    }
}
