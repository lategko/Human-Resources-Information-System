<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\LeaveCredit;
use App\Models\LeaveCreditTransaction;
use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function index(Request $request): View
    {
        [$from, $to] = $this->range($request);
        $attendance = $this->attendanceQuery($request, $from, $to);
        $leaves = $this->leaveQuery($request, $from, $to);
        $officeHead = $request->user()->isOfficeHead();

        return view('reports.index', [
            'attendance' => $attendance->with('employee.department')->paginate(20)->withQueryString(),
            'leaves' => $leaves->with('employee.department', 'leaveType')->paginate(20)->withQueryString(),
            'departments' => Department::where('is_active', true)
                ->when($officeHead, fn ($query) => $query->where('head_user_id', $request->user()->id))
                ->orderBy('name')->get(),
            'leaveTypes' => LeaveType::where('is_active', true)->orderBy('sort_order')->get(),
            'employmentStatuses' => $this->employeeQuery($request)->distinct()->orderBy('employment_status')->pluck('employment_status'),
            'selectedEmployee' => $request->filled('employee_id')
                ? $this->employeeQuery($request)->with(['department', 'position'])->first()
                : null,
            'from' => $from,
            'to' => $to,
            'attendanceCount' => (clone $this->attendanceQuery($request, $from, $to))->count(),
            'leaveCount' => (clone $this->leaveQuery($request, $from, $to))->count(),
        ]);
    }

    public function export(Request $request, string $type)
    {
        abort_unless(in_array($type, ['employees', 'attendance', 'leave'], true), 404);
        [$from, $to] = $this->range($request);
        $filename = $type.'-report-'.$from.'-to-'.$to.'.csv';

        return Response::streamDownload(function () use ($request, $type, $from, $to) {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Municipality of Camalaniugan']);
            fputcsv($output, ['Human Resource Management Office', 'Province of Cagayan, Philippines']);
            fputcsv($output, [ucfirst($type).' report']);
            fputcsv($output, ['Reporting period', $from, 'through', $to]);
            fputcsv($output, ['Generated', now()->format('Y-m-d H:i:s')]);
            fputcsv($output, []);
            if ($type === 'employees') {
                fputcsv($output, ['Employee ID', 'Last name', 'First name', 'Middle name', 'Department', 'Position', 'Employment status', 'Date hired']);
                $this->employeeQuery($request)->with(['department', 'position'])->orderBy('last_name')->chunk(200, function ($employees) use ($output) {
                    foreach ($employees as $employee) {
                        fputcsv($output, [$employee->employee_no, $employee->last_name, $employee->first_name, $employee->middle_name, $employee->department?->name ?? 'Department not assigned', $employee->position?->title, $employee->employment_status, $employee->date_hired?->format('Y-m-d')]);
                    }
                });
            } elseif ($type === 'attendance') {
                fputcsv($output, ['Employee ID', 'Employee name', 'Department', 'Date', 'Time in', 'Time out', 'Net hours', 'Late minutes', 'Undertime minutes', 'Unapproved extra minutes', 'Calendar classification', 'Attendance status', 'Calculation warning']);
                $this->attendanceQuery($request, $from, $to)->with('employee.department')->orderBy('work_date')->chunk(200, function ($records) use ($output) {
                    foreach ($records as $record) {
                        fputcsv($output, [$record->employee->employee_no, $record->employee->full_name, $record->employee->department?->name ?? 'Department not assigned', $record->work_date->format('Y-m-d'), $record->time_in, $record->time_out, $record->total_working_hours, $record->late_minutes, $record->undertime_minutes, $record->unapproved_extra_minutes, $record->calendar_classification, $record->display_status, $record->calculation_warning]);
                    }
                });
            } else {
                fputcsv($output, ['Application ID', 'Employee ID', 'Employee name', 'Department', 'Leave type', 'Start date', 'End date', 'Working days', 'Status', 'Filed date']);
                $this->leaveQuery($request, $from, $to)->with(['employee.department', 'leaveType'])->orderBy('created_at')->chunk(200, function ($applications) use ($output) {
                    foreach ($applications as $application) {
                        fputcsv($output, [$application->id, $application->employee->employee_no, $application->employee->full_name, $application->employee->department?->name ?? 'Department not assigned', $application->leaveType->name, $application->start_date?->format('Y-m-d'), $application->end_date?->format('Y-m-d'), $application->verified_working_days ?? $application->working_days, $application->status, $application->filed_at?->format('Y-m-d')]);
                    }
                });
            }
            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exportLeaveCredits(Request $request, string $report)
    {
        $allowed = ['balances', 'ledger', 'accrual', 'applications', 'categories', 'special', 'wellness', 'terminal', 'monetization', 'utilization'];
        abort_unless(in_array($report, $allowed, true), 404);
        [$from, $to] = $this->range($request);
        $file = 'leave-'.$report.'-'.$from.'-to-'.$to.'.csv';

        return Response::streamDownload(function () use ($request, $report, $from, $to): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Municipality of Camalaniugan']);
            fputcsv($output, ['Human Resource Management Office']);
            fputcsv($output, [ucfirst(str_replace('-', ' ', $report)).' report']);
            fputcsv($output, ['Period', $from, 'through', $to]);
            fputcsv($output, ['Generated', now()->format('Y-m-d H:i:s')]);
            fputcsv($output, []);

            if ($report === 'balances' || $report === 'terminal') {
                fputcsv($output, ['Employee ID', 'Employee name', 'Department', 'Employment status', 'Leave category', 'Beginning balance', 'Earned', 'Used', 'Adjustments', 'Available']);
                $this->filteredCreditQuery($request)
                    ->with(['employee.department', 'leaveType'])
                    ->when($report === 'terminal', fn ($query) => $query->whereHas('employee', fn ($employee) => $employee->where('employment_status', '!=', 'Active')))
                    ->orderBy('id')
                    ->chunk(200, function ($credits) use ($output): void {
                        foreach ($credits as $credit) {
                            fputcsv($output, [
                                $credit->employee->employee_no,
                                $credit->employee->full_name,
                                $credit->employee->department?->name ?? 'Department not assigned',
                                $credit->employee->employment_status,
                                $credit->leaveType->name,
                                $credit->beginning_balance,
                                $credit->earned_credits,
                                $credit->used_credits,
                                $credit->adjustments,
                                number_format($credit->balance, 2, '.', ''),
                            ]);
                        }
                    });
            } elseif (in_array($report, ['ledger', 'accrual'], true)) {
                fputcsv($output, ['Employee ID', 'Employee name', 'Department', 'Leave category', 'Transaction type', 'Effective date', 'Accrual month', 'Amount', 'Previous balance', 'New balance', 'Related application', 'Remarks']);
                $transactions = LeaveCreditTransaction::query()
                    ->with(['leaveCredit.employee.department', 'leaveCredit.leaveType'])
                    ->whereHas('leaveCredit', fn ($credits) => $this->applyCreditFilters($credits, $request))
                    ->whereDate('effective_date', '>=', $from)
                    ->whereDate('effective_date', '<=', $to)
                    ->when($report === 'accrual', fn ($query) => $query->where('type', 'monthly_accrual'))
                    ->orderBy('effective_date')
                    ->orderBy('id');
                $transactions->chunk(200, function ($items) use ($output): void {
                    foreach ($items as $transaction) {
                        $credit = $transaction->leaveCredit;
                        fputcsv($output, [
                            $credit->employee->employee_no,
                            $credit->employee->full_name,
                            $credit->employee->department?->name ?? 'Department not assigned',
                            $credit->leaveType->name,
                            $transaction->type,
                            $transaction->effective_date->format('Y-m-d'),
                            $transaction->accrual_month?->format('Y-m'),
                            $transaction->amount,
                            $transaction->previous_balance,
                            $transaction->balance_after,
                            $transaction->leave_application_id,
                            $transaction->remarks,
                        ]);
                    }
                });
            } else {
                fputcsv($output, ['Application ID', 'Employee ID', 'Employee name', 'Department', 'Employment status', 'Leave category', 'Start date', 'End date', 'Working days', 'Status', 'Filed date', 'Requested credit days', 'Reason']);
                $applications = $this->leaveQuery($request, $from, $to)
                    ->with(['employee.department', 'leaveType'])
                    ->when($report === 'special', fn ($query) => $query->whereHas('leaveType', fn ($types) => $types->whereNotIn('code', ['VACATION', 'SICK', 'OTHER', 'WELLNESS', 'MONETIZATION', 'TERMINAL'])))
                    ->when($report === 'wellness', fn ($query) => $query->whereHas('leaveType', fn ($types) => $types->where('code', 'WELLNESS')))
                    ->when($report === 'monetization', fn ($query) => $query->whereHas('leaveType', fn ($types) => $types->where('code', 'MONETIZATION')))
                    ->when($report === 'terminal', fn ($query) => $query->whereHas('leaveType', fn ($types) => $types->where('code', 'TERMINAL')))
                    ->orderBy('created_at');
                $applications->chunk(200, function ($items) use ($output): void {
                    foreach ($items as $application) {
                        fputcsv($output, [
                            $application->id,
                            $application->employee->employee_no,
                            $application->employee->full_name,
                            $application->employee->department?->name ?? 'Department not assigned',
                            $application->employee->employment_status,
                            $application->leaveType->name,
                            $application->start_date?->format('Y-m-d'),
                            $application->end_date?->format('Y-m-d'),
                            $application->verified_working_days ?? $application->working_days,
                            $application->status,
                            $application->filed_at?->format('Y-m-d'),
                            $application->policy_details['requested_credit_days'] ?? null,
                            $application->policy_details['justifiable_reason'] ?? null,
                        ]);
                    }
                });
            }
            fclose($output);
        }, $file, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filteredCreditQuery(Request $request): Builder
    {
        return $this->applyCreditFilters(LeaveCredit::query(), $request);
    }

    private function applyCreditFilters(Builder $query, Request $request): Builder
    {
        if ($request->user()->isOfficeHead()) {
            $query->whereHas('employee.department', fn ($department) => $department->where('head_user_id', $request->user()->id));
        }
        if ($request->filled('department_id') && ! $request->user()->isOfficeHead()) {
            $query->whereHas('employee', fn ($employee) => $employee->where('department_id', $request->integer('department_id')));
        }
        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->integer('employee_id'));
        }
        if ($request->filled('leave_type_id')) {
            $query->where('leave_type_id', $request->integer('leave_type_id'));
        }
        if ($request->filled('employment_status')) {
            $query->whereHas('employee', fn ($employee) => $employee->where('employment_status', $request->string('employment_status')));
        }
        if ($request->filled('q')) {
            $search = $request->string('q')->toString();
            $query->whereHas('employee', fn ($employee) => $employee
                ->where('employee_no', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%"));
        }

        return $query;
    }

    private function range(Request $request): array
    {
        $dates = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        return [$dates['date_from'] ?? now()->startOfMonth()->toDateString(), $dates['date_to'] ?? today()->toDateString()];
    }

    private function employeeQuery(Request $request): Builder
    {
        $query = Employee::query()->whereNull('archived_at');
        $this->applyScope($query, $request);

        return $query;
    }

    private function attendanceQuery(Request $request, string $from, string $to): Builder
    {
        $query = AttendanceRecord::query()
            ->whereDate('work_date', '>=', $from)
            ->whereDate('work_date', '<=', $to);
        $this->applyScope($query, $request);
        if ($request->filled('attendance_status')) {
            $query->where('status', $request->string('attendance_status'));
        }

        return $query;
    }

    private function leaveQuery(Request $request, string $from, string $to): Builder
    {
        $query = LeaveApplication::query()->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59']);
        $this->applyScope($query, $request);
        if ($request->filled('application_status')) {
            $query->where('status', $request->string('application_status'));
        }
        if ($request->filled('leave_type_id')) {
            $query->where('leave_type_id', $request->integer('leave_type_id'));
        }
        return $query;
    }

    private function applyScope(Builder $query, Request $request): void
    {
        $isEmployeeQuery = $query->getModel() instanceof Employee;
        if ($request->user()->isOfficeHead()) {
            $query->whereHas($isEmployeeQuery ? 'department' : 'employee.department', fn ($department) => $department->where('head_user_id', $request->user()->id));
        }
        if ($request->filled('department_id') && ! $request->user()->isOfficeHead()) {
            if ($isEmployeeQuery) {
                $query->where('department_id', $request->integer('department_id'));
            } else {
                $query->whereHas('employee', fn ($employee) => $employee->where('department_id', $request->integer('department_id')));
            }
        }
        if ($request->filled('employee_id')) {
            $isEmployeeQuery ? $query->whereKey($request->integer('employee_id')) : $query->where('employee_id', $request->integer('employee_id'));
        }
        if ($request->filled('employment_status')) {
            $isEmployeeQuery
                ? $query->where('employment_status', $request->string('employment_status'))
                : $query->whereHas('employee', fn ($employee) => $employee->where('employment_status', $request->string('employment_status')));
        }
    }
}
