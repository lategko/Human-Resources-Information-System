<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveApplication;
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

        return view('reports.index', [
            'attendance' => $attendance->with('employee.department')->paginate(20)->withQueryString(),
            'leaves' => $leaves->with('employee.department', 'leaveType')->paginate(20)->withQueryString(),
            'departments' => Department::where('is_active', true)->orderBy('name')->get(),
            'employees' => Employee::query()->whereNull('archived_at')
                ->when($request->user()->isOfficeHead(), fn ($query) => $query->whereHas('department', fn ($department) => $department->where('head_user_id', $request->user()->id)))
                ->orderBy('last_name')->get(),
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
            if ($type === 'employees') {
                fputcsv($output, ['Employee ID', 'Last name', 'First name', 'Middle name', 'Department', 'Position', 'Employment status', 'Date hired']);
                $this->employeeQuery($request)->with(['department','position'])->orderBy('last_name')->chunk(200, function ($employees) use ($output) {
                    foreach ($employees as $employee) {
                        fputcsv($output, [$employee->employee_no, $employee->last_name, $employee->first_name, $employee->middle_name, $employee->department->name, $employee->position?->title, $employee->employment_status, $employee->date_hired?->format('Y-m-d')]);
                    }
                });
            } elseif ($type === 'attendance') {
                fputcsv($output, ['Employee ID', 'Employee name', 'Department', 'Date', 'Time in', 'Time out', 'Hours', 'Late minutes', 'Undertime minutes', 'Status']);
                $this->attendanceQuery($request, $from, $to)->with('employee.department')->orderBy('work_date')->chunk(200, function ($records) use ($output) {
                    foreach ($records as $record) {
                        fputcsv($output, [$record->employee->employee_no, $record->employee->full_name, $record->employee->department->name, $record->work_date->format('Y-m-d'), $record->time_in, $record->time_out, $record->total_working_hours, $record->late_minutes, $record->undertime_minutes, $record->status]);
                    }
                });
            } else {
                fputcsv($output, ['Application ID', 'Employee ID', 'Employee name', 'Department', 'Leave type', 'Start date', 'End date', 'Working days', 'Status', 'Filed date']);
                $this->leaveQuery($request, $from, $to)->with(['employee.department','leaveType'])->orderBy('created_at')->chunk(200, function ($applications) use ($output) {
                    foreach ($applications as $application) {
                        fputcsv($output, [$application->id, $application->employee->employee_no, $application->employee->full_name, $application->employee->department->name, $application->leaveType->name, $application->start_date?->format('Y-m-d'), $application->end_date?->format('Y-m-d'), $application->verified_working_days ?? $application->working_days, $application->status, $application->filed_at?->format('Y-m-d')]);
                    }
                });
            }
            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
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
        $query = AttendanceRecord::query()->whereBetween('work_date', [$from, $to]);
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
    }
}
