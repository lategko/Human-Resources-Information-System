<?php

namespace Tests\Feature;

use App\Models\AttendanceException;
use App\Models\AttendanceRecord;
use App\Models\AttendanceWorkplace;
use App\Models\AttendanceWorkplaceAssignment;
use App\Models\AttendanceWorkplaceAssignmentHistory;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\AttendanceWorkplaceAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GpsAttendanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_gps_time_in_out_accuracy_geofence_and_duplicate_actions(): void
    {
        [$admin, $employeeUser, $employee] = $this->accounts();
        $workplace = AttendanceWorkplace::create([
            'name' => 'Municipal Hall',
            'address' => 'Camalaniugan',
            'latitude' => 18.2735000,
            'longitude' => 121.6725000,
            'radius_meters' => 100,
            'required_accuracy_meters' => 50,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        $employee->update(['attendance_workplace_id' => $workplace->id]);

        $this->actingAs($employeeUser)->post(route('attendance.time-in'), [
            'latitude' => 18.2735000,
            'longitude' => 121.6725000,
            'accuracy_meters' => 20,
        ])->assertRedirect();

        $record = AttendanceRecord::where('employee_id', $employee->id)->whereDate('work_date', today())->firstOrFail();
        $this->assertSame('gps', $record->source);
        $this->assertSame('Verified', $record->gps_verification_status);
        $this->assertSame($workplace->id, $record->time_in_workplace_id);

        $this->actingAs($employeeUser)->post(route('attendance.time-in'), [
            'latitude' => 18.2735000,
            'longitude' => 121.6725000,
            'accuracy_meters' => 20,
        ])->assertSessionHasErrors('attendance');

        $this->actingAs($employeeUser)->post(route('attendance.time-out'), [
            'latitude' => 18.2735000,
            'longitude' => 121.6725000,
            'accuracy_meters' => 20,
        ])->assertRedirect();
        $this->assertNotNull($record->fresh()->time_out);
    }

    public function test_outside_radius_and_insufficient_accuracy_are_blocked(): void
    {
        [$admin, $employeeUser, $employee] = $this->accounts();
        $workplace = AttendanceWorkplace::create([
            'name' => 'Municipal Hall',
            'latitude' => 18.2735000,
            'longitude' => 121.6725000,
            'radius_meters' => 100,
            'required_accuracy_meters' => 30,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        $employee->update(['attendance_workplace_id' => $workplace->id]);

        $this->actingAs($employeeUser)->post(route('attendance.time-in'), [
            'latitude' => 18.2735000,
            'longitude' => 121.6725000,
            'accuracy_meters' => 60,
        ])->assertSessionHasErrors('accuracy_meters');

        $this->actingAs($employeeUser)->post(route('attendance.time-in'), [
            'latitude' => 18.2835000,
            'longitude' => 121.6725000,
            'accuracy_meters' => 20,
        ])->assertSessionHasErrors('location');

        $this->assertDatabaseMissing('attendance_records', ['employee_id' => $employee->id]);
    }

    public function test_admin_controls_and_employee_exception_review_are_authorized(): void
    {
        [$admin, $employeeUser, $employee] = $this->accounts();

        $this->actingAs($admin)->post(route('attendance.workplaces.store'), [
            'name' => 'Field Office',
            'address' => 'Camalaniugan',
            'latitude' => 18.2735000,
            'longitude' => 121.6725000,
            'radius_meters' => 150,
            'required_accuracy_meters' => 40,
            'is_active' => '1',
        ])->assertRedirect();

        $workplace = AttendanceWorkplace::where('name', 'Field Office')->firstOrFail();
        $this->actingAs($admin)->post(route('attendance.employees.workplace', $employee), [
            'attendance_workplace_id' => $workplace->id,
            'effective_from' => today()->toDateString(),
            'reason' => 'Employee work location assignment.',
        ])->assertRedirect();

        $this->actingAs($admin)->put(route('attendance.workplaces.update', $workplace), [
            'name' => 'Field Office',
            'address' => 'Updated address, Camalaniugan',
            'latitude' => 18.274,
            'longitude' => 121.673,
            'radius_meters' => 175,
            'required_accuracy_meters' => 40,
            'is_active' => '1',
        ])->assertRedirect();

        $this->assertSame($workplace->id, $employee->fresh()->attendance_workplace_id);
        $this->assertSame(2, $workplace->history()->count());
        $this->actingAs($admin)
            ->get(route('attendance.index'))
            ->assertOk()
            ->assertSee('Department Assignment')
            ->assertSee('Bulk Employee Assignment')
            ->assertSee('Individual Assignment');

        $this->actingAs($employeeUser)->post(route('attendance.exceptions.store'), [
            'attendance_date' => today()->toDateString(),
            'requested_action' => 'time_in',
            'explanation' => 'GPS signal unavailable in the designated area.',
        ])->assertRedirect();

        $exception = AttendanceException::where('employee_id', $employee->id)->firstOrFail();
        $this->actingAs($admin)->post(route('attendance.exceptions.review', $exception), [
            'status' => 'Rejected',
            'review_remarks' => 'No verified technical evidence.',
        ])->assertRedirect();

        $this->assertSame('Rejected', $exception->fresh()->status);
        $this->actingAs($employeeUser)->post(route('attendance.workplaces.store'), [
            'name' => 'Unauthorized Workplace',
            'latitude' => 18.2735000,
            'longitude' => 121.6725000,
            'radius_meters' => 100,
            'required_accuracy_meters' => 50,
            'is_active' => '1',
        ])->assertForbidden();
    }

    public function test_department_default_and_future_individual_override_resolve_for_gps(): void
    {
        [$admin, $employeeUser, $employee] = $this->accounts();
        $departmentWorkplace = $this->createWorkplace($admin, 'Department Office', 18.2735, 121.6725);
        $individualWorkplace = $this->createWorkplace($admin, 'Field Office', 18.2745, 121.6735);

        $this->actingAs($admin)->post(route('attendance.workplaces.assign-department'), [
            'department_id' => $employee->department_id,
            'attendance_workplace_id' => $departmentWorkplace->id,
            'effective_from' => today()->toDateString(),
            'reason' => 'Department default for the current work location.',
        ])->assertRedirect();

        $assignmentService = app(AttendanceWorkplaceAssignmentService::class);
        $this->assertSame(
            $departmentWorkplace->id,
            $assignmentService->resolve($employee, today()->toDateString())?->id
        );

        $futureDate = today()->addDay()->toDateString();
        $this->actingAs($admin)->post(route('attendance.employees.workplace', $employee), [
            'attendance_workplace_id' => $individualWorkplace->id,
            'effective_from' => $futureDate,
            'reason' => 'Approved future field assignment.',
        ])->assertRedirect();

        $this->assertSame($departmentWorkplace->id, $assignmentService->resolve($employee, today()->toDateString())?->id);
        $this->assertSame($individualWorkplace->id, $assignmentService->resolve($employee, $futureDate)?->id);

        $this->actingAs($employeeUser)->post(route('attendance.time-in'), [
            'latitude' => 18.2735,
            'longitude' => 121.6725,
            'accuracy_meters' => 20,
        ])->assertRedirect();
        $record = AttendanceRecord::where('employee_id', $employee->id)->whereDate('work_date', today())->firstOrFail();
        $this->assertSame($departmentWorkplace->id, $record->time_in_workplace_id);
        $this->assertTrue(AttendanceWorkplaceAssignmentHistory::query()
            ->where('employee_id', $employee->id)
            ->where('new_workplace_id', $departmentWorkplace->id)
            ->where('assignment_type', 'department')
            ->whereDate('effective_date', today())
            ->exists());
    }

    public function test_bulk_assignment_is_transactional_and_writes_per_employee_history(): void
    {
        [$admin, $employeeUser, $employee] = $this->accounts();
        $secondEmployee = Employee::create([
            'department_id' => $employee->department_id,
            'employee_no' => 'GPS-0002',
            'first_name' => 'Second',
            'last_name' => 'Employee',
            'employment_status' => 'Active',
        ]);
        $workplace = $this->createWorkplace($admin, 'Bulk Office', 18.2735, 121.6725);
        $payload = [
            'employee_ids' => [$employee->id, $secondEmployee->id],
            'attendance_workplace_id' => $workplace->id,
            'effective_from' => today()->toDateString(),
            'reason' => 'Bulk assignment to the shared worksite.',
        ];

        $this->actingAs($admin)
            ->post(route('attendance.workplaces.assign-bulk'), $payload)
            ->assertRedirect();

        $this->assertSame(2, AttendanceWorkplaceAssignment::where('assignment_type', 'bulk')->count());
        $this->assertSame(2, AttendanceWorkplaceAssignmentHistory::where('assignment_type', 'bulk')->count());
        $this->assertSame($workplace->id, app(AttendanceWorkplaceAssignmentService::class)->resolve($secondEmployee, today()->toDateString())?->id);

        $this->actingAs($admin)
            ->post(route('attendance.workplaces.assign-bulk'), $payload)
            ->assertSessionHasErrors('effective_from');

        $this->assertSame(2, AttendanceWorkplaceAssignment::where('assignment_type', 'bulk')->count());
    }

    public function test_department_assignment_preserves_individual_override_unless_confirmed(): void
    {
        [$admin, $employeeUser, $employee] = $this->accounts();
        $individual = $this->createWorkplace($admin, 'Individual Site', 18.2735, 121.6725);
        $departmentDefault = $this->createWorkplace($admin, 'Department Site', 18.2745, 121.6735);
        $replacementDefault = $this->createWorkplace($admin, 'Replacement Site', 18.2755, 121.6745);
        $service = app(AttendanceWorkplaceAssignmentService::class);

        $this->actingAs($admin)->post(route('attendance.employees.workplace', $employee), [
            'attendance_workplace_id' => $individual->id,
            'effective_from' => today()->toDateString(),
            'reason' => 'Individual assignment should take priority.',
        ])->assertRedirect();

        $this->actingAs($admin)
            ->getJson(route('attendance.workplaces.department-preview', [
                'department_id' => $employee->department_id,
                'effective_from' => today()->toDateString(),
            ]))
            ->assertOk()
            ->assertJsonPath('employees.0.assignment_type', 'individual')
            ->assertJsonPath('employees.0.workplace', 'Individual Site');

        $this->actingAs($employeeUser)->post(route('attendance.time-in'), [
            'latitude' => 18.2735,
            'longitude' => 121.6725,
            'accuracy_meters' => 20,
        ])->assertRedirect();
        $attendanceRecord = AttendanceRecord::where('employee_id', $employee->id)->whereDate('work_date', today())->firstOrFail();

        $this->actingAs($admin)->post(route('attendance.workplaces.assign-department'), [
            'department_id' => $employee->department_id,
            'attendance_workplace_id' => $departmentDefault->id,
            'effective_from' => today()->toDateString(),
            'reason' => 'Set department default without replacing individual work sites.',
        ])->assertRedirect();
        $this->assertSame($individual->id, $service->resolve($employee, today()->toDateString())?->id);

        $futureDate = today()->addDay()->toDateString();
        $this->actingAs($admin)->post(route('attendance.workplaces.assign-department'), [
            'department_id' => $employee->department_id,
            'attendance_workplace_id' => $replacementDefault->id,
            'effective_from' => $futureDate,
            'reason' => 'Explicitly replace individual assignment with the department default.',
            'replace_individual_assignments' => '1',
        ])->assertRedirect();

        $this->assertSame($replacementDefault->id, $service->resolve($employee, $futureDate)?->id);
        $this->assertTrue(AttendanceWorkplaceAssignmentHistory::query()
            ->where('employee_id', $employee->id)
            ->where('assignment_type', 'department')
            ->whereDate('effective_date', $futureDate)
            ->where('new_workplace_id', $replacementDefault->id)
            ->exists());
        $this->assertSame(1, AttendanceWorkplaceAssignment::where('employee_id', $employee->id)
            ->whereNull('attendance_workplace_id')
            ->whereDate('effective_from', $futureDate)
            ->count());
        $this->assertSame($individual->id, $attendanceRecord->fresh()->time_in_workplace_id);

        $this->actingAs($admin)
            ->get(route('attendance.index', ['assignment_tab' => 'individual', 'selected_employee' => $employee->id]))
            ->assertOk()
            ->assertSee('CURRENT WORKPLACE')
            ->assertSee('Individual Site')
            ->assertSee('Individual');
    }

    public function test_only_authorized_admins_can_create_employee_or_department_assignments(): void
    {
        [, $employeeUser, $employee] = $this->accounts();
        $officeHead = User::create([
            'name' => 'Workplace Office Head',
            'email' => 'workplace-head@example.test',
            'password' => 'TestAccount123!',
            'role' => 'office_head',
            'is_active' => true,
        ]);
        $mayor = User::create([
            'name' => 'Workplace Mayor',
            'email' => 'workplace-mayor@example.test',
            'password' => 'TestAccount123!',
            'role' => 'mayor',
            'is_active' => true,
        ]);
        $workplace = AttendanceWorkplace::create([
            'name' => 'Restricted Office',
            'latitude' => 18.2735,
            'longitude' => 121.6725,
            'radius_meters' => 100,
            'required_accuracy_meters' => 50,
            'is_active' => true,
        ]);
        $payload = [
            'attendance_workplace_id' => $workplace->id,
            'effective_from' => today()->toDateString(),
            'reason' => 'Unauthorized assignment attempt.',
        ];

        $this->actingAs($employeeUser)
            ->post(route('attendance.employees.workplace', $employee), $payload)
            ->assertForbidden();
        $this->actingAs($employeeUser)
            ->post(route('attendance.workplaces.assign-department'), $payload + [
                'department_id' => $employee->department_id,
            ])
            ->assertForbidden();
        $this->actingAs($employeeUser)
            ->post(route('attendance.workplaces.assign-bulk'), $payload + [
                'employee_ids' => [$employee->id],
            ])
            ->assertForbidden();
        $this->actingAs($employeeUser)
            ->getJson(route('attendance.workplaces.department-preview', [
                'department_id' => $employee->department_id,
                'effective_from' => today()->toDateString(),
            ]))
            ->assertForbidden();

        foreach ([$officeHead, $mayor] as $unauthorizedUser) {
            $this->actingAs($unauthorizedUser)
                ->post(route('attendance.employees.workplace', $employee), $payload)
                ->assertForbidden();
            $this->actingAs($unauthorizedUser)
                ->post(route('attendance.workplaces.assign-department'), $payload + [
                    'department_id' => $employee->department_id,
                ])
                ->assertForbidden();
            $this->actingAs($unauthorizedUser)
                ->post(route('attendance.workplaces.assign-bulk'), $payload + [
                    'employee_ids' => [$employee->id],
                ])
                ->assertForbidden();
        }
    }

    public function test_workplace_geocoding_returns_search_results_and_reverse_address(): void
    {
        [$admin] = $this->accounts();
        Http::fake([
            'https://nominatim.openstreetmap.org/search*' => Http::response([
                [
                    'lat' => '18.2735',
                    'lon' => '121.6725',
                    'display_name' => 'Municipal Hall, Camalaniugan, Philippines',
                ],
            ]),
            'https://nominatim.openstreetmap.org/reverse*' => Http::response([
                'display_name' => 'Municipal Hall, Camalaniugan, Philippines',
            ]),
        ]);

        $this->actingAs($admin)
            ->getJson(route('attendance.workplaces.geocode.search', ['q' => 'Municipal Hall']))
            ->assertOk()
            ->assertJsonPath('0.latitude', 18.2735)
            ->assertJsonPath('0.longitude', 121.6725);

        $this->actingAs($admin)
            ->getJson(route('attendance.workplaces.geocode.reverse', [
                'latitude' => 18.2735,
                'longitude' => 121.6725,
            ]))
            ->assertOk()
            ->assertJsonPath('display_name', 'Municipal Hall, Camalaniugan, Philippines');

        Http::assertSent(fn ($request) => $request->hasHeader('User-Agent', 'Camalaniugan-Municipal-HRIS/1.0 ('.config('app.url').')'));
    }

    public function test_workplace_geocoding_is_admin_only_and_validates_search_coordinates(): void
    {
        [$admin, $employeeUser] = $this->accounts();

        $this->actingAs($employeeUser)
            ->getJson(route('attendance.workplaces.geocode.search', ['q' => 'Municipal Hall']))
            ->assertForbidden();

        $this->actingAs($employeeUser)
            ->getJson(route('attendance.workplaces.geocode.reverse', [
                'latitude' => 100,
                'longitude' => 121,
            ]))
            ->assertForbidden();

        $this->actingAs($admin)
            ->getJson(route('attendance.workplaces.geocode.search', ['q' => 'x']))
            ->assertUnprocessable();

        $this->actingAs($admin)
            ->getJson(route('attendance.workplaces.geocode.reverse', [
                'latitude' => 100,
                'longitude' => 121,
            ]))
            ->assertUnprocessable();

        auth()->logout();
        $this->getJson(route('attendance.workplaces.geocode.search', ['q' => 'Municipal Hall']))
            ->assertUnauthorized();
    }

    private function accounts(): array
    {
        $department = Department::create(['name' => 'GPS Test Department', 'is_active' => true]);
        $admin = User::create([
            'name' => 'GPS HRMO',
            'email' => 'gps-hrmo@example.test',
            'password' => 'Password123!',
            'role' => 'admin',
            'is_active' => true,
            'account_setup_completed' => true,
        ]);
        $employeeUser = User::create([
            'name' => 'GPS Employee',
            'email' => 'gps-employee@example.test',
            'password' => 'Password123!',
            'role' => 'employee',
            'is_active' => true,
            'account_setup_completed' => true,
        ]);
        $employee = Employee::create([
            'user_id' => $employeeUser->id,
            'department_id' => $department->id,
            'employee_no' => 'GPS-0001',
            'first_name' => 'GPS',
            'last_name' => 'Employee',
            'employment_status' => 'Active',
        ]);

        return [$admin, $employeeUser, $employee];
    }

    private function createWorkplace(User $admin, string $name, float $latitude, float $longitude): AttendanceWorkplace
    {
        return AttendanceWorkplace::create([
            'name' => $name,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'radius_meters' => 100,
            'required_accuracy_meters' => 50,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
    }
}
