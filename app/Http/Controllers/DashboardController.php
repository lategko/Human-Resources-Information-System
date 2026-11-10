<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\LeaveCredit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $employee = $user->employee;

        if ($user->isEmployee()) {
            abort_unless($employee, 403, 'Your account is not linked to an employee record.');
            $attendance = $employee->attendanceRecords()
                ->whereBetween('work_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
                ->get();

            return view('dashboard', [
                'role' => 'employee',
                'employee' => $employee->load('department', 'position'),
                'applications' => $employee->leaveApplications()->with('leaveType')->latest()->limit(5)->get(),
                'credits' => $employee->leaveCredits()->with('leaveType')->get(),
                'recentAttendance' => $employee->attendanceRecords()->latest('work_date')->limit(5)->get(),
                'upcomingLeaves' => $employee->leaveApplications()
                    ->where('status', 'Approved')
                    ->whereBetween('start_date', [today(), today()->addDays(30)])
                    ->with('leaveType')
                    ->orderBy('start_date')
                    ->limit(5)
                    ->get(),
                'presentCount' => $attendance->whereIn('status', ['Present', 'Late'])->count(),
                'lateCount' => $attendance->where('late_minutes', '>', 0)->count(),
                'pageTitle' => 'My workspace',
            ]);
        }

        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ]);
        $dateFrom = $filters['date_from'] ?? now()->subDays(13)->toDateString();
        $dateTo = $filters['date_to'] ?? today()->toDateString();
        $canFilterDepartment = $user->canManageHr() || $user->isAdministrator();
        $departmentId = $canFilterDepartment && ! $user->isOfficeHead()
            ? ($filters['department_id'] ?? null)
            : null;

        $scopeEmployees = function ($query) use ($user, $departmentId): void {
            if ($user->isOfficeHead()) {
                $query->whereHas('department', fn ($department) => $department->where('head_user_id', $user->id));
            } elseif ($departmentId) {
                $query->where('department_id', $departmentId);
            }
        };
        $scopeRelated = fn ($query) => $query->whereHas('employee', $scopeEmployees);

        $employees = Employee::query()
            ->whereNull('archived_at')
            ->where('employment_status', 'Active');
        $scopeEmployees($employees);

        $attendanceToday = AttendanceRecord::query()->whereDate('work_date', today());
        $scopeRelated($attendanceToday);

        $attendanceThisMonth = AttendanceRecord::query()
            ->whereBetween('work_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()]);
        $scopeRelated($attendanceThisMonth);

        $leaves = LeaveApplication::query();
        $scopeRelated($leaves);

        $trendQuery = AttendanceRecord::query()->whereBetween('work_date', [$dateFrom, $dateTo]);
        $scopeRelated($trendQuery);
        $trendRecords = $trendQuery
            ->get(['work_date', 'status'])
            ->groupBy(fn (AttendanceRecord $record) => $record->work_date->toDateString())
            ->sortKeys();
        $trend = $trendRecords->map(fn ($records, $date) => [
            'label' => Carbon::parse($date)->format('M j'),
            'present' => $records->where('status', 'Present')->count(),
            'late' => $records->where('status', 'Late')->count(),
            'absent' => $records->where('status', 'Absent')->count(),
        ])->values();
        $leaveTrend = (clone $leaves)
            ->whereBetween('created_at', [$dateFrom.' 00:00:00', $dateTo.' 23:59:59'])
            ->get(['created_at'])
            ->groupBy(fn (LeaveApplication $application) => $application->created_at->toDateString())
            ->sortKeys()
            ->map(fn ($applications, $date) => [
                'label' => Carbon::parse($date)->format('M j'),
                'applications' => $applications->count(),
            ])
            ->values();

        $pendingStatus = match ($user->role) {
            'office_head' => 'Under Office Head Review',
            'hrmo' => 'Under HRMO Verification',
            default => 'Pending Final Approval',
        };
        $pendingTasks = $user->isAdministrator() && ! $user->canFinalApprove()
            ? collect()
            : (clone $leaves)
                ->where('status', $pendingStatus)
                ->with(['employee.department', 'leaveType'])
                ->oldest('created_at')
                ->limit(8)
                ->get();

        $departmentCountQuery = Department::query()->where('is_active', true);
        if ($user->isOfficeHead()) {
            $departmentCountQuery->where('head_user_id', $user->id);
        } elseif ($departmentId) {
            $departmentCountQuery->whereKey($departmentId);
        }

        $viewData = [
            'role' => $user->role,
            'employeeCount' => $employees->count(),
            'departmentCount' => $departmentCountQuery->count(),
            'presentCount' => (clone $attendanceToday)->whereIn('status', ['Present', 'Late'])->count(),
            'absentCount' => (clone $attendanceToday)->where('status', 'Absent')->count(),
            'lateCount' => (clone $attendanceToday)->where('late_minutes', '>', 0)->count(),
            'monthlyPresentCount' => (clone $attendanceThisMonth)->whereIn('status', ['Present', 'Late'])->count(),
            'monthlyLateCount' => (clone $attendanceThisMonth)->where('late_minutes', '>', 0)->count(),
            'monthlyAbsentCount' => (clone $attendanceThisMonth)->where('status', 'Absent')->count(),
            'leaveTodayCount' => (clone $leaves)->where('status', 'Approved')
                ->whereDate('start_date', '<=', today())
                ->whereDate('end_date', '>=', today())
                ->count(),
            'pendingCount' => $user->isAdministrator() && ! $user->canFinalApprove()
                ? 0
                : (clone $leaves)->where('status', $pendingStatus)->count(),
            'pendingTasks' => $pendingTasks,
            'upcomingLeaves' => (clone $leaves)
                ->where('status', 'Approved')
                ->whereBetween('start_date', [today(), today()->addDays(30)])
                ->with(['employee.department', 'leaveType'])
                ->orderBy('start_date')
                ->limit(6)
                ->get(),
            'recentApplications' => (clone $leaves)
                ->with(['employee.department', 'leaveType'])
                ->latest()
                ->limit(6)
                ->get(),
            'recentAttendance' => (clone $attendanceToday)
                ->with('employee.department')
                ->latest()
                ->limit(6)
                ->get(),
            'chartData' => [
                'labels' => $trend->pluck('label'),
                'present' => $trend->pluck('present'),
                'late' => $trend->pluck('late'),
                'absent' => $trend->pluck('absent'),
            ],
            'leaveChartData' => [
                'labels' => $leaveTrend->pluck('label'),
                'applications' => $leaveTrend->pluck('applications'),
            ],
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'selectedDepartmentId' => $departmentId,
            'departments' => $canFilterDepartment
                ? Department::where('is_active', true)->orderBy('name')->get()
                : collect(),
            'activeCreditCount' => LeaveCredit::query()
                ->whereHas('employee', function ($query) use ($scopeEmployees) {
                    $query->whereNull('archived_at');
                    $query->where('employment_status', 'Active');
                    $scopeEmployees($query);
                })
                ->count(),
            'pageTitle' => match ($user->role) {
                'office_head' => 'Department overview',
                'hrmo' => 'HR operations',
                default => 'Executive overview',
            },
        ];

        if ($user->isAdministrator()) {
            $viewData['departmentDistribution'] = Department::query()
                ->where('is_active', true)
                ->when($departmentId, fn ($query) => $query->whereKey($departmentId))
                ->withCount(['employees' => fn ($query) => $query
                    ->whereNull('archived_at')
                    ->where('employment_status', 'Active')])
                ->orderBy('name')
                ->get();
            $viewData['recentActivities'] = AuditLog::query()
                ->with('user:id,name')
                ->latest()
                ->limit(6)
                ->get(['id', 'user_id', 'action', 'subject_type', 'subject_id', 'created_at']);
        }

        return view('dashboard', $viewData);
    }
}
