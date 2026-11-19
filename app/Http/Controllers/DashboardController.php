<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeImport;
use App\Models\LeaveApplication;
use App\Models\LeaveApprovalHistory;
use App\Models\LeaveCredit;
use App\Services\EmployeeLeaveEntitlementSummary;
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
                ->whereDate('work_date', '>=', now()->startOfMonth()->toDateString())
                ->whereDate('work_date', '<=', now()->endOfMonth()->toDateString())
                ->get();

            return view('dashboard', [
                'role' => 'employee',
                'employee' => $employee->load('department', 'position'),
                'applications' => $employee->leaveApplications()->with('leaveType')->latest()->limit(5)->get(),
                'applicationStatusCounts' => $employee->leaveApplications()
                    ->selectRaw('status, count(*) as aggregate')
                    ->groupBy('status')
                    ->pluck('aggregate', 'status'),
                'credits' => $employee->leaveCredits()->with(['leaveType', 'transactions'])->get(),
                'specialEntitlements' => app(EmployeeLeaveEntitlementSummary::class)->forYear($employee),
                'recentAttendance' => $employee->attendanceRecords()->latest('work_date')->limit(5)->get(),
                'recentNotifications' => $user->notifications()->latest()->limit(5)->get(),
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
        $inactiveEmployees = Employee::query()
            ->whereNull('archived_at')
            ->where('employment_status', '!=', 'Active');
        $scopeEmployees($inactiveEmployees);

        $attendanceToday = AttendanceRecord::query()->whereDate('work_date', today());
        $scopeRelated($attendanceToday);

        $attendanceThisMonth = AttendanceRecord::query()
            ->whereDate('work_date', '>=', now()->startOfMonth()->toDateString())
            ->whereDate('work_date', '<=', now()->endOfMonth()->toDateString());
        $scopeRelated($attendanceThisMonth);

        $leaves = LeaveApplication::query();
        $scopeRelated($leaves);

        $trendQuery = AttendanceRecord::query()
            ->whereDate('work_date', '>=', $dateFrom)
            ->whereDate('work_date', '<=', $dateTo);
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

        $pendingStatus = match (true) {
            $user->isOfficeHead() => 'Under Office Head Review',
            $user->isHrmo() => 'Under HRMO Verification',
            default => 'Pending Final Approval',
        };
        if ($user->isAdministrator() && ! $user->canFinalApprove()) {
            $pendingTasks = collect();
        } elseif ($user->canFinalApprove()) {
            $pendingTasks = (clone $leaves)
                ->where(function ($query) use ($user) {
                    $query->where('status', 'Pending Final Approval')
                        ->orWhere(function ($alternate) use ($user) {
                            $alternate->where('status', 'Under Alternate Office Head Review')
                                ->where('office_head_reviewer_id', $user->id);
                        });
                })
                ->with(['employee.department', 'leaveType'])
                ->oldest('created_at')
                ->limit(8)
                ->get();
        } elseif ($user->isOfficeHead()) {
            $pendingTasks = LeaveApplication::query()
                ->where('office_head_reviewer_id', $user->id)
                ->whereIn('status', ['Under Office Head Review', 'Under Alternate Office Head Review'])
                ->with(['employee.department', 'leaveType'])
                ->oldest('created_at')
                ->limit(8)
                ->get();
        } else {
            $pendingTasks = (clone $leaves)
                ->where('status', $pendingStatus)
                ->with(['employee.department', 'leaveType'])
                ->oldest('created_at')
                ->limit(8)
                ->get();
        }
        $pendingFinalTasks = $user->canFinalApprove()
            ? (clone $leaves)
                ->where('status', 'Pending Final Approval')
                ->with(['employee.department', 'leaveType'])
                ->oldest('created_at')
                ->limit(8)
                ->get()
            : collect();
        $pendingAuthorizedFinalTasks = $user->canApproveMayorLeave()
            ? LeaveApplication::query()
                ->where('status', 'Pending Authorized Final Approval')
                ->where('assigned_final_approver_id', $user->id)
                ->with(['employee.department', 'leaveType'])
                ->oldest('created_at')
                ->limit(8)
                ->get()
            : collect();
        $pendingAlternateTasks = $user->canFinalApprove()
            ? (clone $leaves)
                ->where('status', 'Under Alternate Office Head Review')
                ->where('office_head_reviewer_id', $user->id)
                ->with(['employee.department', 'leaveType'])
                ->oldest('created_at')
                ->limit(8)
                ->get()
            : collect();

        $departmentCountQuery = Department::query()->where('is_active', true);
        if ($user->isOfficeHead()) {
            $departmentCountQuery->where('head_user_id', $user->id);
        } elseif ($departmentId) {
            $departmentCountQuery->whereKey($departmentId);
        }

        $leaveCreditStats = null;
        if ($user->isHrmo()) {
            $creditScope = function ($query) use ($scopeEmployees): void {
                $query->whereHas('employee', $scopeEmployees);
            };
            $regularCredits = LeaveCredit::query()->whereHas('leaveType', fn ($types) => $types->whereIn('code', ['VACATION', 'SICK']));
            $creditScope($regularCredits);
            $vlCredits = (clone $regularCredits)->whereHas('leaveType', fn ($types) => $types->where('code', 'VACATION'));
            $slCredits = (clone $regularCredits)->whereHas('leaveType', fn ($types) => $types->where('code', 'SICK'));
            $accrualMonth = now()->subMonth()->startOfMonth()->toDateString();
            $monthlyAccrual = \App\Models\LeaveCreditTransaction::query()
                ->where('type', 'monthly_accrual')
                ->whereDate('accrual_month', $accrualMonth)
                ->whereHas('leaveCredit', fn ($credits) => $creditScope($credits));
            $leaveCreditStats = [
                'vl_employees' => (clone $vlCredits)->whereRaw('(beginning_balance + earned_credits + adjustments - used_credits) > 0')->distinct('employee_id')->count('employee_id'),
                'sl_employees' => (clone $slCredits)->whereRaw('(beginning_balance + earned_credits + adjustments - used_credits) > 0')->distinct('employee_id')->count('employee_id'),
                'low_balance_employees' => (clone $regularCredits)->whereRaw('(beginning_balance + earned_credits + adjustments - used_credits) <= 3')->distinct('employee_id')->count('employee_id'),
                'monthly_accrual' => (float) (clone $monthlyAccrual)->sum('amount'),
                'accrual_month' => Carbon::parse($accrualMonth)->format('F Y'),
                'pending' => (clone $leaves)->whereIn('status', ['Submitted', 'Under Office Head Review', 'Under Alternate Office Head Review', 'Under HRMO Verification', 'Pending Final Approval'])->count(),
                'approved' => (clone $leaves)->where('status', 'Approved')->count(),
                'disapproved' => (clone $leaves)->where('status', 'Disapproved')->count(),
                'special_applications' => (clone $leaves)->whereHas('leaveType', fn ($types) => $types->whereNotIn('code', ['VACATION', 'SICK', 'OTHER', 'WELLNESS', 'MONETIZATION', 'TERMINAL']))->count(),
            ];
        }

        $viewData = [
            'employeeCount' => $employees->count(),
            'departmentCount' => $departmentCountQuery->count(),
            'inactiveEmployeeCount' => $inactiveEmployees->count(),
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
                : ($user->canFinalApprove()
                    ? (clone $leaves)->where(function ($query) use ($user) {
                        $query->where('status', 'Pending Final Approval')
                            ->orWhere(function ($alternate) use ($user) {
                                $alternate->where('status', 'Under Alternate Office Head Review')
                                    ->where('office_head_reviewer_id', $user->id);
                            });
                    })->count()
                    : ($user->isOfficeHead()
                    ? (clone $pendingTasks)->count()
                    : (clone $leaves)->where('status', $pendingStatus)->count())),
            'pendingTasks' => $pendingTasks,
            'pendingFinalTasks' => $pendingFinalTasks,
            'pendingAlternateTasks' => $pendingAlternateTasks,
            'pendingAuthorizedFinalTasks' => $pendingAuthorizedFinalTasks,
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
            'leaveCreditStats' => $leaveCreditStats,
            'departmentDistribution' => Department::query()
                ->where('is_active', true)
                ->when($departmentId, fn ($query) => $query->whereKey($departmentId))
                ->when($user->isOfficeHead(), fn ($query) => $query->where('head_user_id', $user->id))
                ->withCount(['employees' => fn ($query) => $query
                    ->whereNull('archived_at')
                    ->where('employment_status', 'Active')])
                ->orderBy('name')
                ->get(),
            'approvalHistory' => ($user->isOfficeHead() || $user->canFinalApprove())
                ? LeaveApprovalHistory::query()
                    ->with(['user:id,name', 'application.employee.department', 'application.leaveType'])
                    ->when($user->isOfficeHead(), fn ($query) => $query->whereHas(
                        'application.employee.department',
                        fn ($department) => $department->where('head_user_id', $user->id)
                    ))
                    ->latest()
                    ->limit(8)
                    ->get()
                : collect(),
            'approvedLeaveCount' => (clone $leaves)->where('status', 'Approved')->count(),
            'returnedLeaveCount' => (clone $leaves)->where('status', 'Returned for Correction')->count(),
            'pageTitle' => match ($user->role) {
                'office_head' => 'Department overview',
                'hrmo' => 'HR operations',
                'admin' => $user->canFinalApprove() ? 'Mayor’s executive overview' : 'Administration overview',
                default => 'Municipal overview',
            },
            'role' => $user->canFinalApprove() ? 'mayor' : ($user->isAdministrator() ? 'administrator' : $user->role),
            'pendingEmployeeImports' => $user->isHrmo()
                ? EmployeeImport::query()
                    ->whereIn('status', ['mapping_required', 'ready_for_import', 'partially_ready', 'importing', 'completed_with_errors', 'failed', 'pending_review', 'mapping_approved', 'review_required'])
                    ->with('uploader:id,name')
                    ->oldest()
                    ->limit(5)
                    ->get()
                : collect(),
        ];

        if (($user->isAdministrator() && ! $user->canFinalApprove()) || $user->isHrmo()) {
            $viewData['recentActivities'] = AuditLog::query()
                ->with('user:id,name')
                ->latest()
                ->limit(6)
                ->get(['id', 'user_id', 'action', 'subject_type', 'subject_id', 'created_at']);
        }

        return view('dashboard', $viewData);
    }
}
