<?php

namespace App\Http\Controllers;

use App\Models\AttendanceException;
use App\Models\AttendanceRecord;
use App\Models\AttendanceWorkplace;
use App\Models\AttendanceWorkplaceAssignmentHistory;
use App\Models\AttendanceWorkplaceHistory;
use App\Models\Department;
use App\Models\Employee;
use App\Models\SystemSetting;
use App\Models\WorkSchedule;
use App\Services\AttendanceWorkHoursCalculator;
use App\Services\AttendanceWorkplaceAssignmentService;
use App\Services\AuditLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AttendanceController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $assignmentService = app(AttendanceWorkplaceAssignmentService::class);
        $visibleAssignmentMap = [];
        if ($user->isAdministrator()) {
            $assignmentQuery = Employee::with(['department', 'attendanceWorkplace'])
                ->whereNull('archived_at')
                ->where('employment_status', 'Active');
            $assignmentSearch = trim((string) $request->query('assignment_search', ''));
            if ($assignmentSearch !== '') {
                foreach (preg_split('/\s+/', $assignmentSearch, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $term) {
                    $assignmentQuery->where(fn ($employees) => $employees
                        ->where('employee_no', 'like', "%{$term}%")
                        ->orWhere('first_name', 'like', "%{$term}%")
                        ->orWhere('middle_name', 'like', "%{$term}%")
                        ->orWhere('last_name', 'like', "%{$term}%")
                        ->orWhere('suffix', 'like', "%{$term}%"));
                }
            }
            if ($request->filled('assignment_department')) {
                $assignmentQuery->where('department_id', $request->integer('assignment_department'));
            }
            $hasEffectiveAssignmentFilter = $request->filled('assignment_workplace') || $request->filled('assignment_status');
            if ($hasEffectiveAssignmentFilter) {
                $candidates = $assignmentQuery->orderBy('last_name')->orderBy('first_name')->get();
                $candidateAssignments = $assignmentService->effectiveAssignments($candidates, today()->toDateString());
                $candidates = $candidates->filter(function (Employee $employee) use ($request, $candidateAssignments) {
                    $assignment = $candidateAssignments[$employee->id] ?? ['workplace' => null];
                    $workplaceFilter = $request->query('assignment_workplace');
                    if ($workplaceFilter === 'unassigned' && $assignment['workplace']) {
                        return false;
                    }
                    if (is_numeric($workplaceFilter) && (int) $assignment['workplace']?->id !== (int) $workplaceFilter) {
                        return false;
                    }
                    if ($request->query('assignment_status') === 'assigned' && ! $assignment['workplace']) {
                        return false;
                    }
                    if ($request->query('assignment_status') === 'unassigned' && $assignment['workplace']) {
                        return false;
                    }

                    return true;
                })->values();
                $assignmentPage = LengthAwarePaginator::resolveCurrentPage('assignments_page');
                $assignmentEmployees = new LengthAwarePaginator(
                    $candidates->forPage($assignmentPage, 100)->values(),
                    $candidates->count(),
                    100,
                    $assignmentPage,
                    [
                        'path' => LengthAwarePaginator::resolveCurrentPath(),
                        'pageName' => 'assignments_page',
                        'query' => $request->except('assignments_page'),
                    ],
                );
                $visibleAssignmentMap = $assignmentEmployees->getCollection()
                    ->mapWithKeys(fn (Employee $employee) => [$employee->id => $candidateAssignments[$employee->id]])
                    ->all();
            } else {
                $assignmentEmployees = $assignmentQuery
                    ->orderBy('last_name')
                    ->orderBy('first_name')
                    ->paginate(100, ['*'], 'assignments_page')
                    ->appends($request->except('assignments_page'));
                $visibleAssignmentMap = $assignmentService
                    ->effectiveAssignments($assignmentEmployees->getCollection(), today()->toDateString());
            }
        } else {
            $assignmentEmployees = new LengthAwarePaginator([], 0, 100, 1, [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'pageName' => 'assignments_page',
            ]);
        }
        $selectedAssignmentEmployee = $user->isAdministrator() && ($request->filled('selected_employee') || old('employee_id'))
            ? Employee::with(['department:id,name', 'position:id,title'])
                ->whereNull('archived_at')
                ->where('employment_status', 'Active')
                ->find((int) old('employee_id', $request->input('selected_employee')))
            : null;
        if ($selectedAssignmentEmployee) {
            $visibleAssignmentMap[$selectedAssignmentEmployee->id] ??= $assignmentService
                ->effectiveAssignments(collect([$selectedAssignmentEmployee]), today()->toDateString())[$selectedAssignmentEmployee->id];
        }
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
        if ($request->filled('employee_id') && ! $user->isEmployee()) {
            $query->where('employee_id', $request->integer('employee_id'));
        }
        if ($request->filled('gps_status')) {
            $query->where('gps_verification_status', $request->string('gps_status'));
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
            'assignmentEmployees' => $assignmentEmployees,
            'effectiveWorkplaceAssignments' => $visibleAssignmentMap,
            'selectedAttendanceEmployee' => $user->isAdministrator() && $request->filled('employee_id')
                ? Employee::whereNull('archived_at')->find($request->integer('employee_id'))
                : null,
            'selectedAssignmentEmployee' => $selectedAssignmentEmployee,
            'selectedScheduleEmployee' => ($user->canManageHr() || $user->isAdministrator()) && old('employee_id')
                ? Employee::whereNull('archived_at')->find((int) old('employee_id'))
                : null,
            'assignmentHistory' => $user->isAdministrator()
                ? AttendanceWorkplaceAssignmentHistory::with([
                    'employee', 'department', 'previousWorkplace', 'newWorkplace', 'assignedBy',
                ])->latest()->paginate(10, ['*'], 'assignment_history_page')->withQueryString()
                : collect(),
            'workSchedules' => $user->canManageHr() || $user->isAdministrator()
                ? WorkSchedule::with(['employee', 'creator'])->latest('effective_from')->paginate(10, ['*'], 'schedules_page')
                : collect(),
            'defaultSchedule' => WorkSchedule::defaults(),
            'workplaces' => $user->isAdministrator()
                ? AttendanceWorkplace::withCount('employees')->latest()->get()
                : collect(),
            'todayRecord' => $user->employee
                ? AttendanceRecord::where('employee_id', $user->employee->id)->whereDate('work_date', today())->first()
                : null,
            'attendanceExceptions' => AttendanceException::query()
                ->with(['employee.department', 'record', 'reviewer'])
                ->when($user->isEmployee(), fn ($exceptions) => $exceptions->where('employee_id', $user->employee?->id ?? 0))
                ->when($user->isOfficeHead(), fn ($exceptions) => $exceptions->whereHas(
                    'employee.department',
                    fn ($department) => $department->where('head_user_id', $user->id)
                ))
                ->latest()
                ->limit(10)
                ->get(),
        ]);
    }

    public function timeIn(
        Request $request,
        AttendanceWorkHoursCalculator $hoursCalculator,
        AttendanceWorkplaceAssignmentService $assignmentService,
        AuditLogger $auditLogger
    ): RedirectResponse {
        $employee = $request->user()->employee;
        abort_unless($employee, 403, 'Your account is not linked to an employee record.');
        $data = $this->validateGpsPayload($request);
        $workplace = $this->verifiedWorkplace($employee, $data, $assignmentService);

        DB::transaction(function () use ($employee, $workplace, $data, $hoursCalculator, $auditLogger) {
            $record = AttendanceRecord::query()->where('employee_id', $employee->id)->whereDate('work_date', today())->lockForUpdate()->first();
            if ($record?->time_in) {
                throw ValidationException::withMessages(['attendance' => 'You have already recorded time in for today.']);
            }
            $resolved = WorkSchedule::resolveFor($employee, today()->toDateString());
            $now = now();
            $timeIn = $now->format('H:i:s');
            $metrics = $hoursCalculator->calculateMetrics($timeIn, $record?->time_out, $resolved['schedule'], $resolved['calendar_classification']);
            $record ??= new AttendanceRecord(['employee_id' => $employee->id, 'work_date' => today()]);
            $record->fill([
                'time_in' => $timeIn,
                'status' => $metrics['late_minutes'] > 0 ? 'Late' : 'Present',
                'source' => 'gps',
                'calendar_classification' => $resolved['calendar_classification'],
                'schedule_snapshot' => $resolved['schedule'],
                ...$metrics,
                'time_in_latitude' => $data['latitude'],
                'time_in_longitude' => $data['longitude'],
                'time_in_accuracy_meters' => $data['accuracy_meters'],
                'time_in_distance_meters' => $data['distance_meters'],
                'time_in_workplace_id' => $workplace->id,
                'time_in_verified_at' => $now,
                'gps_verification_status' => 'Verified',
            ]);
            $record->save();
            $auditLogger->record('attendance.gps_time_in', $record, [
                'workplace_id' => $workplace->id,
                'distance_meters' => $data['distance_meters'],
                'accuracy_meters' => $data['accuracy_meters'],
            ]);
        });

        return back()->with('success', 'Time in recorded and location verified.');
    }

    public function timeOut(
        Request $request,
        AttendanceWorkHoursCalculator $hoursCalculator,
        AttendanceWorkplaceAssignmentService $assignmentService,
        AuditLogger $auditLogger
    ): RedirectResponse {
        $employee = $request->user()->employee;
        abort_unless($employee, 403, 'Your account is not linked to an employee record.');
        $data = $this->validateGpsPayload($request);
        $workplace = $this->verifiedWorkplace($employee, $data, $assignmentService);

        DB::transaction(function () use ($employee, $workplace, $data, $hoursCalculator, $auditLogger) {
            $record = AttendanceRecord::query()->where('employee_id', $employee->id)->whereDate('work_date', today())->lockForUpdate()->first();
            abort_unless($record && $record->time_in, 409, 'You must record time in before time out.');
            if ($record->time_out) {
                throw ValidationException::withMessages(['attendance' => 'You have already recorded time out for today.']);
            }
            $now = now();
            $resolved = WorkSchedule::resolveFor($employee, today()->toDateString());
            $metrics = $hoursCalculator->calculateMetrics(
                $record->time_in,
                $now->format('H:i:s'),
                $record->schedule_snapshot ?? $resolved['schedule'],
                $record->calendar_classification ?? $resolved['calendar_classification']
            );
            $record->fill([
                'time_out' => $now->format('H:i:s'),
                ...$metrics,
                'time_out_latitude' => $data['latitude'],
                'time_out_longitude' => $data['longitude'],
                'time_out_accuracy_meters' => $data['accuracy_meters'],
                'time_out_distance_meters' => $data['distance_meters'],
                'time_out_workplace_id' => $workplace->id,
                'time_out_verified_at' => $now,
                'gps_verification_status' => 'Verified',
            ]);
            $record->save();
            $auditLogger->record('attendance.gps_time_out', $record, [
                'workplace_id' => $workplace->id,
                'distance_meters' => $data['distance_meters'],
                'accuracy_meters' => $data['accuracy_meters'],
            ]);
        });

        return back()->with('success', 'Time out recorded and location verified.');
    }

    private function validateGpsPayload(Request $request): array
    {
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy_meters' => ['required', 'numeric', 'min:0', 'max:100000'],
        ]);
        $accuracy = (float) $data['accuracy_meters'];
        if (! is_finite($accuracy) || $accuracy <= 0) {
            throw ValidationException::withMessages([
                'accuracy_meters' => 'Your location accuracy is insufficient. Please try again in an area with a better GPS signal.',
            ]);
        }

        return [
            'latitude' => (float) $data['latitude'],
            'longitude' => (float) $data['longitude'],
            'accuracy_meters' => $accuracy,
        ];
    }

    private function verifiedWorkplace(
        Employee $employee,
        array &$data,
        AttendanceWorkplaceAssignmentService $assignmentService
    ): AttendanceWorkplace {
        $workplace = $assignmentService->resolve($employee, today()->toDateString());
        if (! $workplace) {
            throw ValidationException::withMessages([
                'workplace' => 'No authorized active workplace is assigned to your employee record.',
            ]);
        }
        if ($data['accuracy_meters'] > (float) $workplace->required_accuracy_meters) {
            throw ValidationException::withMessages([
                'accuracy_meters' => 'Your location accuracy is insufficient. Please try again in an area with a better GPS signal.',
            ]);
        }
        $distance = $this->distanceMeters(
            $data['latitude'],
            $data['longitude'],
            (float) $workplace->latitude,
            (float) $workplace->longitude
        );
        if ($distance > (float) $workplace->radius_meters) {
            throw ValidationException::withMessages([
                'location' => 'You are outside the authorized attendance area. Please move within the approved workplace location.',
            ]);
        }
        $data['distance_meters'] = round($distance, 2);

        return $workplace;
    }

    private function distanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000;
        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lonDelta / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(max(0, 1 - $a)));
    }

    public function storeWorkplace(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless($request->user()->isAdministrator(), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius_meters' => ['required', 'integer', 'between:10,10000'],
            'required_accuracy_meters' => ['required', 'integer', 'between:5,1000'],
            'is_active' => ['required', 'boolean'],
        ]);
        $data['created_by'] = $request->user()->id;

        DB::transaction(function () use ($data, $request, $auditLogger) {
            $workplace = AttendanceWorkplace::create($data);
            AttendanceWorkplaceHistory::create([
                'attendance_workplace_id' => $workplace->id,
                'changed_by' => $request->user()->id,
                'latitude' => $workplace->latitude,
                'longitude' => $workplace->longitude,
                'radius_meters' => $workplace->radius_meters,
                'required_accuracy_meters' => $workplace->required_accuracy_meters,
                'is_active' => $workplace->is_active,
            ]);
            $auditLogger->record('attendance.workplace_created', $workplace);
        });

        return back()->with('success', 'Authorized workplace location saved.');
    }

    public function updateWorkplace(Request $request, AttendanceWorkplace $workplace, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless($request->user()->isAdministrator(), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius_meters' => ['required', 'integer', 'between:10,10000'],
            'required_accuracy_meters' => ['required', 'integer', 'between:5,1000'],
            'is_active' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($workplace, $data, $request, $auditLogger) {
            $workplace = AttendanceWorkplace::query()->lockForUpdate()->findOrFail($workplace->id);
            $workplace->update($data);
            AttendanceWorkplaceHistory::create([
                'attendance_workplace_id' => $workplace->id,
                'changed_by' => $request->user()->id,
                'latitude' => $workplace->latitude,
                'longitude' => $workplace->longitude,
                'radius_meters' => $workplace->radius_meters,
                'required_accuracy_meters' => $workplace->required_accuracy_meters,
                'is_active' => $workplace->is_active,
            ]);
            $auditLogger->record('attendance.workplace_updated', $workplace);
        });

        return back()->with('success', 'Authorized workplace location updated.');
    }

    public function assignWorkplace(Request $request, Employee $employee, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'attendance_workplace_id' => ['nullable', 'integer', Rule::exists('attendance_workplaces', 'id')->where('is_active', true)],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        app(AttendanceWorkplaceAssignmentService::class)->assignIndividual(
            $employee->id,
            $data['attendance_workplace_id'] ? (int) $data['attendance_workplace_id'] : null,
            $data['effective_from'],
            $request->user()->id,
            $data['reason'],
            $auditLogger,
        );

        return back()->with('success', 'Individual workplace assignment saved with its effective date.');
    }

    public function assignDepartmentWorkplace(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where('is_active', true)],
            'attendance_workplace_id' => ['required', 'integer', Rule::exists('attendance_workplaces', 'id')->where('is_active', true)],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:2000'],
            'replace_individual_assignments' => ['nullable', 'boolean'],
        ]);
        $result = app(AttendanceWorkplaceAssignmentService::class)->assignDepartment(
            (int) $data['department_id'],
            (int) $data['attendance_workplace_id'],
            $data['effective_from'],
            $request->user()->id,
            $data['reason'],
            (bool) ($data['replace_individual_assignments'] ?? false),
            $auditLogger,
        );

        return back()->with(
            'success',
            "Department workplace default saved for {$result['employees']} employee(s); {$result['replaced']} individual override(s) were explicitly replaced."
        );
    }

    public function departmentWorkplaceAssignmentPreview(
        Request $request,
        AttendanceWorkplaceAssignmentService $assignmentService,
    ): JsonResponse {
        $data = $request->validate([
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where('is_active', true)],
            'effective_from' => ['required', 'date_format:Y-m-d'],
        ]);
        $employees = Employee::query()
            ->where('department_id', $data['department_id'])
            ->whereNull('archived_at')
            ->where('employment_status', 'Active')
            ->with('department')
            ->orderBy('last_name')
            ->get();
        $assignments = $assignmentService->effectiveAssignments($employees, $data['effective_from']);

        return response()->json([
            'employees' => $employees->map(fn (Employee $employee) => [
                'employee_no' => $employee->employee_no,
                'name' => $employee->full_name,
                'workplace' => $assignments[$employee->id]['workplace']?->name,
                'assignment_type' => $assignments[$employee->id]['assignment_type'],
            ])->values(),
        ]);
    }

    public function searchWorkplaceAssignmentEmployees(
        Request $request,
        AttendanceWorkplaceAssignmentService $assignmentService,
    ): JsonResponse {
        abort_unless($request->user()->isAdministrator(), 403);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ]);
        $query = Employee::query()
            ->with(['department:id,name', 'position:id,title'])
            ->whereNull('archived_at')
            ->where('employment_status', 'Active');
        $search = trim($filters['q'] ?? '');
        if ($search !== '') {
            foreach (preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $term) {
                $query->where(fn ($employees) => $employees
                    ->where('employee_no', 'like', "%{$term}%")
                    ->orWhere('first_name', 'like', "%{$term}%")
                    ->orWhere('middle_name', 'like', "%{$term}%")
                    ->orWhere('last_name', 'like', "%{$term}%")
                    ->orWhere('suffix', 'like', "%{$term}%"));
            }
        }

        $page = (int) ($filters['page'] ?? 1);
        $employees = $query
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->offset(($page - 1) * 20)
            ->limit(21)
            ->get(['id', 'employee_no', 'first_name', 'middle_name', 'last_name', 'suffix', 'department_id', 'position_id']);
        $hasMore = $employees->count() > 20;
        $employees = $employees->take(20);
        $assignments = $assignmentService->effectiveAssignments($employees, today()->toDateString());

        return response()->json([
            'results' => $employees->map(fn (Employee $employee) => [
                'id' => $employee->id,
                'employee_no' => $employee->employee_no,
                'name' => $employee->full_name,
                'label' => $employee->employee_no.' · '.$employee->full_name,
                'department' => $employee->department?->name,
                'position' => $employee->position?->title,
                'workplace' => $assignments[$employee->id]['workplace']?->name,
                'assignment_type' => $assignments[$employee->id]['assignment_type'],
                'effective_from' => $assignments[$employee->id]['effective_from']?->format('M j, Y'),
            ])->values(),
            'has_more' => $hasMore,
            'page' => $page,
        ]);
    }

    public function bulkAssignWorkplaces(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1', 'max:500'],
            'employee_ids.*' => ['required', 'integer', 'distinct', 'exists:employees,id'],
            'attendance_workplace_id' => ['required', 'integer', Rule::exists('attendance_workplaces', 'id')->where('is_active', true)],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $count = app(AttendanceWorkplaceAssignmentService::class)->assignEmployees(
            array_map('intval', $data['employee_ids']),
            (int) $data['attendance_workplace_id'],
            $data['effective_from'],
            $request->user()->id,
            $data['reason'],
            $auditLogger,
        );

        return back()->with('success', "{$count} employee workplace assignment(s) saved.");
    }

    public function searchWorkplaceLocations(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdministrator(), 403);
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:200'],
        ]);

        return $this->lookupOpenStreetMap('search', [
            'q' => trim($data['q']),
            'format' => 'jsonv2',
            'addressdetails' => 1,
            'limit' => 5,
            'countrycodes' => 'ph',
        ]);
    }

    public function reverseWorkplaceLocation(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdministrator(), 403);
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        return $this->lookupOpenStreetMap('reverse', [
            'lat' => $data['latitude'],
            'lon' => $data['longitude'],
            'format' => 'jsonv2',
            'addressdetails' => 1,
        ]);
    }

    private function lookupOpenStreetMap(string $type, array $parameters): JsonResponse
    {
        $cacheKey = 'attendance-workplace-geocode:'.hash('sha256', $type.json_encode($parameters));
        $result = Cache::remember($cacheKey, now()->addDay(), function () use ($type, $parameters) {
            return Cache::lock('attendance-workplace-geocode-nominatim', 10)->block(8, function () use ($type, $parameters) {
                $lastRequestAt = (float) Cache::get('attendance-workplace-geocode-last-request', 0);
                $waitMicros = max(0, (int) (1_100_000 - ((microtime(true) - $lastRequestAt) * 1_000_000)));
                if ($waitMicros > 0) {
                    usleep($waitMicros);
                }

                try {
                    $response = Http::acceptJson()
                        ->withHeaders([
                            'User-Agent' => 'Camalaniugan-Municipal-HRIS/1.0 ('.config('app.url').')',
                            'Referer' => config('app.url'),
                        ])
                        ->timeout(8)
                        ->get('https://nominatim.openstreetmap.org/'.$type, $parameters);
                } catch (ConnectionException) {
                    return ['error' => 'OpenStreetMap location search is temporarily unavailable. Please try again.'];
                } finally {
                    Cache::put('attendance-workplace-geocode-last-request', microtime(true), now()->addMinute());
                }

                if (! $response->successful()) {
                    return ['error' => 'OpenStreetMap location search is temporarily unavailable. Please try again.'];
                }

                $body = $response->json();
                if ($type === 'reverse') {
                    return [
                        'display_name' => is_array($body) ? ($body['display_name'] ?? null) : null,
                    ];
                }

                return collect(is_array($body) ? $body : [])
                    ->filter(fn ($place) => isset($place['lat'], $place['lon'], $place['display_name']))
                    ->map(fn ($place) => [
                        'latitude' => (float) $place['lat'],
                        'longitude' => (float) $place['lon'],
                        'display_name' => (string) $place['display_name'],
                    ])
                    ->values()
                    ->all();
            });
        });

        if (isset($result['error'])) {
            Cache::forget($cacheKey);

            return response()->json(['message' => $result['error']], 502);
        }

        return response()->json($result);
    }

    public function storeException(Request $request): RedirectResponse
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 403);
        $data = $request->validate([
            'attendance_date' => ['required', 'date'],
            'requested_action' => ['required', Rule::in(['time_in', 'time_out', 'correction'])],
            'explanation' => ['required', 'string', 'max:2000'],
            'supporting_document' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
        ]);
        $path = isset($data['supporting_document'])
            ? $data['supporting_document']->store('attendance-exceptions/'.$employee->id, 'local')
            : null;
        AttendanceException::create([
            'employee_id' => $employee->id,
            'attendance_record_id' => AttendanceRecord::where('employee_id', $employee->id)->whereDate('work_date', $data['attendance_date'])->value('id'),
            'attendance_date' => $data['attendance_date'],
            'requested_action' => $data['requested_action'],
            'explanation' => $data['explanation'],
            'supporting_document_path' => $path,
        ]);

        return back()->with('success', 'Attendance exception request submitted for HRMO review.');
    }

    public function reviewException(Request $request, AttendanceException $exception, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless($request->user()->isAdministrator(), 403);
        $data = $request->validate([
            'status' => ['required', Rule::in(['Approved', 'Rejected'])],
            'review_remarks' => ['nullable', 'string', 'max:2000'],
        ]);
        abort_unless($exception->status === 'Pending', 409, 'This exception request has already been reviewed.');
        DB::transaction(function () use ($exception, $data, $request, $auditLogger) {
            $locked = AttendanceException::query()->lockForUpdate()->findOrFail($exception->id);
            $locked->update([
                'status' => $data['status'],
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_remarks' => $data['review_remarks'] ?? null,
            ]);
            $auditLogger->record('attendance.exception_reviewed', $locked, [
                'status' => $data['status'],
                'remarks' => $data['review_remarks'] ?? null,
            ]);
        });

        return back()->with('success', 'Attendance exception request reviewed.');
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
                throw ValidationException::withMessages([
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
                throw ValidationException::withMessages([
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
