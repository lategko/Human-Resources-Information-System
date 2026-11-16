<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_lifecycle_preserves_and_displays_attendance_and_leave_history_after_archive(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $department = Department::create(['name' => 'Lifecycle Department']);
        $payload = [
            'employee_no' => 'LIFE-001',
            'department_id' => $department->id,
            'first_name' => 'Lifecycle',
            'last_name' => 'Employee',
            'employment_status' => 'Active',
            'employment_type' => 'Permanent',
            'date_hired' => today()->subYear()->toDateString(),
        ];

        $this->actingAs($admin)->get(route('employees.create'))->assertOk();
        $this->post(route('employees.store'), $payload)->assertRedirect();
        $employee = Employee::where('employee_no', 'LIFE-001')->firstOrFail();
        $this->get(route('employees.show', $employee))->assertOk()->assertSee('Lifecycle Employee');
        $this->get(route('employees.index', ['q' => 'LIFE-001']))->assertOk()->assertSee('Lifecycle Employee');
        $this->post(route('employees.store'), $payload)->assertSessionHasErrors('employee_no');

        $payload['last_name'] = 'Updated Employee';
        $this->put(route('employees.update', $employee), $payload)->assertRedirect(route('employees.show', $employee));
        $this->assertSame('Updated Employee', $employee->fresh()->last_name);
        $employeeCsv = $this->get(route('reports.csv', ['type' => 'employees']))
            ->assertOk()->streamedContent();
        $this->assertStringContainsString('Updated Employee', $employeeCsv);
        $this->assertStringNotContainsString('monthly_salary', strtolower($employeeCsv));

        $attendance = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'work_date' => today(),
            'time_in' => '08:15:00',
            'time_out' => '16:30:00',
            'late_minutes' => 15,
            'undertime_minutes' => 30,
            'status' => 'Late',
        ]);
        $leaveType = LeaveType::create(['name' => 'Lifecycle Leave', 'code' => 'LIFECYCLE']);
        $application = LeaveApplication::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'status' => 'Approved',
            'office_snapshot' => $department->name,
            'last_name_snapshot' => $employee->last_name,
            'first_name_snapshot' => $employee->first_name,
            'working_days' => 1,
            'start_date' => today(),
            'end_date' => today(),
        ]);

        $this->post(route('employees.archive', $employee))->assertRedirect(route('employees.index'));
        $this->assertSoftDeleted($employee);
        $this->assertSame('Archived', $employee->fresh()->employment_status);
        $this->assertDatabaseHas('attendance_records', ['id' => $attendance->id, 'employee_id' => $employee->id]);
        $this->assertDatabaseHas('leave_applications', ['id' => $application->id, 'employee_id' => $employee->id]);

        $this->get(route('attendance.index', ['date' => today()->toDateString()]))
            ->assertOk()->assertSee('Updated Employee')->assertSee('08:15')->assertSee('16:30')
            ->assertSee('15 min')->assertSee('30 min');
        $this->get(route('reports.index', [
            'date_from' => today()->toDateString(),
            'date_to' => today()->toDateString(),
        ]))->assertOk()->assertSee('Updated Employee')->assertSee('Lifecycle Leave');
        $attendanceCsv = $this->get(route('reports.csv', [
            'type' => 'attendance',
            'date_from' => today()->toDateString(),
            'date_to' => today()->toDateString(),
        ]))->assertOk()->streamedContent();
        $this->assertStringContainsString('Updated Employee', $attendanceCsv);
        $this->assertStringContainsString('Late minutes', $attendanceCsv);
        $this->assertStringNotContainsString('deduction', strtolower($attendanceCsv));
        $this->assertStringNotContainsString('salary', strtolower($attendanceCsv));
        $leaveCsv = $this->get(route('reports.csv', [
            'type' => 'leave',
            'date_from' => today()->toDateString(),
            'date_to' => today()->toDateString(),
        ]))->assertOk()->streamedContent();
        $this->assertStringContainsString('Updated Employee', $leaveCsv);
        $this->assertStringContainsString('Lifecycle Leave', $leaveCsv);
    }
}
