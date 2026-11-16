<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\BiometricImport;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\SystemSetting;
use App\Models\WorkSchedule;
use App\Services\AttendanceWorkHoursCalculator;
use App\Services\AuditLogger;
use App\Services\BiometricCsvImportService;
use Illuminate\Support\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AttendanceController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $query = AttendanceRecord::with('employee.department');
        if ($user->isEmployee()) {
            $query->where('employee_id', $user->employee?->id ?? 0);
        } elseif ($user->isOfficeHead()) {
            $query->whereHas('employee.department', fn ($department) => $department->where('head_user_id', $user->id));
        }
        if ($request->filled('date')) {
            $query->whereDate('work_date', $request->date('date'));
        } else {
            $query->whereDate('work_date', '>=', now()->startOfMonth()->toDateString())
                ->whereDate('work_date', '<=', now()->endOfMonth()->toDateString());
        }
        if ($request->filled('department_id') && ! $user->isOfficeHead() && ! $user->isEmployee()) {
            $query->whereHas('employee', fn ($employee) => $employee->where('department_id', $request->integer('department_id')));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('q')) {
            $search = $request->string('q')->toString();
            $query->whereHas('employee', fn ($employee) => $employee->where('employee_no', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"));
        }

        return view('attendance.index', [
            'records' => $query->latest('work_date')->paginate(25)->withQueryString(),
            'departments' => Department::where('is_active', true)
                ->when($user->isOfficeHead(), fn ($departments) => $departments->where('head_user_id', $user->id))
                ->orderBy('name')->get(),
            'employees' => $user->canManageHr() || $user->isAdministrator()
                ? Employee::whereNull('archived_at')->orderBy('last_name')->get()
                : collect(),
            'workSchedules' => $user->canManageHr() || $user->isAdministrator()
                ? WorkSchedule::with(['employee', 'creator'])->latest('effective_from')->paginate(10, ['*'], 'schedules_page')
                : collect(),
            'defaultSchedule' => WorkSchedule::defaults(),
            'imports' => $user->canManageHr() || $user->isAdministrator()
                ? BiometricImport::withCount('records')->latest()->limit(5)->get()
                : collect(),
        ]);
    }

    public function import(
        Request $request,
        BiometricCsvImportService $importer,
        AttendanceWorkHoursCalculator $hoursCalculator,
        AuditLogger $auditLogger
    ): RedirectResponse {
        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt'],
        ]);
        $import = $importer->import($request->file('file'), $request->user()->id, $hoursCalculator);
        $auditLogger->record('attendance.biometric_imported', $import, [
            'imported' => $import->imported_count,
            'errors' => $import->error_count,
        ]);

        return redirect()->route('attendance.index')->with('success', "Import #{$import->id}: {$import->imported_count} row(s) imported, {$import->error_count} error(s).");
    }

    public function importDetails(BiometricImport $biometricImport): View
    {
        abort_unless(auth()->user()->canManageHr() || auth()->user()->isAdministrator(), 403);

        return view('attendance.import', [
            'import' => $biometricImport->load('records.employee'),
        ]);
    }

    public function template(): Response
    {
        return response()->streamDownload(function () {
            $stream = fopen('php://output', 'wb');
            fputcsv($stream, ['biometric_id', 'date', 'time_in', 'time_out']);
            fputcsv($stream, ['BIO-0001', '2026-01-15', '08:00', '17:00']);
            fclose($stream);
        }, 'biometric-attendance-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function storeSchedule(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->whereNull('archived_at')->whereNull('deleted_at')],
            'working_days' => ['required', 'array', 'min:1', 'max:7'],
            'working_days.*' => ['required', 'integer', 'between:1,7', 'distinct'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'different:start_time'],
            'lunch_start' => ['nullable', 'date_format:H:i', 'required_with:lunch_end'],
            'lunch_end' => ['nullable', 'date_format:H:i', 'required_with:lunch_start'],
            'grace_period_minutes' => ['required', 'integer', 'between:0,240'],
            'effective_from' => ['required', 'date', 'after_or_equal:today'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        if (isset($data['lunch_start'], $data['lunch_end'])) {
            $shiftStart = Carbon::createFromFormat('H:i', $data['start_time']);
            $shiftEnd = Carbon::createFromFormat('H:i', $data['end_time']);
            $lunchStart = Carbon::createFromFormat('H:i', $data['lunch_start']);
            $lunchEnd = Carbon::createFromFormat('H:i', $data['lunch_end']);
            if ($shiftEnd->lessThanOrEqualTo($shiftStart)) {
                $shiftEnd->addDay();
            }
            if ($lunchStart->lessThan($shiftStart)) {
                $lunchStart->addDay();
                $lunchEnd->addDay();
            }
            if ($lunchEnd->lessThanOrEqualTo($lunchStart)
                || $lunchStart->lessThan($shiftStart)
                || $lunchEnd->greaterThan($shiftEnd)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'lunch_start' => 'The lunch interval must be after the shift starts and finish before the shift ends.',
                ]);
            }
        }
        $data['employee_id'] = $data['employee_id'] ?? null;
        $data['working_days'] = array_values(array_map('intval', $data['working_days']));
        $data['start_time'] .= ':00';
        $data['end_time'] .= ':00';
        $data['lunch_start'] = isset($data['lunch_start']) ? $data['lunch_start'].':00' : null;
        $data['lunch_end'] = isset($data['lunch_end']) ? $data['lunch_end'].':00' : null;
        $data['created_by'] = $request->user()->id;

        $schedule = DB::transaction(function () use ($data, $auditLogger) {
            if ($data['employee_id'] === null) {
                SystemSetting::query()->whereKey('workweek_days')->lockForUpdate()->firstOrFail();
            } else {
                Employee::query()->lockForUpdate()->findOrFail($data['employee_id']);
            }

            $overlapQuery = WorkSchedule::query()
                ->whereDate('effective_from', '<=', $data['effective_to'] ?? '9999-12-31')
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $data['effective_from']));
            $data['employee_id'] === null
                ? $overlapQuery->whereNull('employee_id')
                : $overlapQuery->where('employee_id', $data['employee_id']);

            if ($overlapQuery->lockForUpdate()->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'effective_from' => 'This schedule overlaps an existing schedule for the selected scope. End the existing schedule first.',
                ]);
            }

            $schedule = WorkSchedule::create($data);
            $auditLogger->record('attendance.schedule_assigned', $schedule, [
                'employee_id' => $schedule->employee_id,
                'effective_from' => $schedule->effective_from->toDateString(),
                'effective_to' => $schedule->effective_to?->toDateString(),
                'working_days' => $schedule->working_days,
                'reason' => $schedule->reason,
            ]);

            return $schedule;
        });

        return back()->with('success', $schedule->employee_id
            ? 'Employee work schedule assigned and audited.'
            : 'Office-wide work schedule saved and audited.');
    }

    public function endSchedule(Request $request, WorkSchedule $workSchedule, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'effective_to' => [
                'required',
                'date',
                'after_or_equal:today',
                'after_or_equal:'.$workSchedule->effective_from->toDateString(),
            ],
        ]);

        DB::transaction(function () use ($workSchedule, $data, $auditLogger) {
            $schedule = WorkSchedule::query()->lockForUpdate()->findOrFail($workSchedule->id);
            $nextSchedule = WorkSchedule::query()
                ->where('employee_id', $schedule->employee_id)
                ->where('id', '!=', $schedule->id)
                ->whereDate('effective_from', '<=', $data['effective_to'])
                ->whereDate('effective_from', '>', $schedule->effective_from)
                ->exists();
            abort_unless(! $nextSchedule, 422, 'The end date must be before the next schedule begins.');
            abort_if($schedule->effective_to && $schedule->effective_to->toDateString() !== $data['effective_to'], 409, 'An ended schedule cannot be changed.');

            $schedule->effective_to = $data['effective_to'];
            $schedule->saveOrFail();
            $auditLogger->record('attendance.schedule_ended', $schedule, [
                'employee_id' => $schedule->employee_id,
                'effective_to' => $schedule->effective_to->toDateString(),
            ]);
        });

        return back()->with('success', 'Schedule end date saved and audited. Historical attendance remains unchanged.');
    }

    public function correct(
        Request $request,
        AttendanceRecord $attendance,
        AttendanceWorkHoursCalculator $hoursCalculator,
        AuditLogger $auditLogger
    ): RedirectResponse {
        abort_unless($request->user()->canManageHr() || $request->user()->isAdministrator(), 403);
        $data = $request->validate([
            'status' => ['required', Rule::in(['Present', 'Late', 'Absent', 'On Leave', 'On Approved Leave', 'Official Business', 'Half Day', 'Rest Day', 'Holiday'])],
            'time_in' => ['nullable', 'date_format:H:i,H:i:s', 'required_if:status,Present,Late'],
            'time_out' => ['nullable', 'date_format:H:i,H:i:s', 'required_if:status,Present,Late'],
            'correction_remarks' => ['required', 'string', 'max:2000'],
        ]);
        DB::transaction(function () use ($attendance, $data, $request, $hoursCalculator, $auditLogger) {
            $locked = AttendanceRecord::query()->lockForUpdate()->findOrFail($attendance->id);
            $employee = $locked->employee;
            $resolved = WorkSchedule::resolveFor($employee, $locked->work_date->toDateString());
            $schedule = $locked->schedule_snapshot ?? $resolved['schedule'];
            $calendarClassification = $locked->calendar_classification ?? $resolved['calendar_classification'];
            $metrics = $hoursCalculator->calculateMetrics(
                $data['time_in'] ?? null,
                $data['time_out'] ?? null,
                $schedule,
                $calendarClassification
            );
            $status = in_array($data['status'], ['Present', 'Late'], true)
                ? ($metrics['late_minutes'] > 0 ? 'Late' : 'Present')
                : $data['status'];
            $locked->fill([
                'status' => $status,
                'time_in' => $data['time_in'] ?? null,
                'time_out' => $data['time_out'] ?? null,
                ...$metrics,
                'calendar_classification' => $calendarClassification,
                'schedule_snapshot' => $schedule,
                'corrected_by' => $request->user()->id,
            ]);
            $locked->save();
            $auditLogger->record('attendance.corrected', $locked, [
                'remarks' => $data['correction_remarks'],
                'status' => $status,
                'changes' => array_intersect_key($metrics + [
                    'status' => $status,
                    'time_in' => $data['time_in'] ?? null,
                    'time_out' => $data['time_out'] ?? null,
                    'calendar_classification' => $calendarClassification,
                ], array_flip(['status', 'time_in', 'time_out', 'total_working_hours', 'late_minutes', 'undertime_minutes', 'unapproved_extra_minutes', 'calendar_classification'])),
            ]);
        });

        return back()->with('success', 'Attendance correction saved with an audit record.');
    }
}
