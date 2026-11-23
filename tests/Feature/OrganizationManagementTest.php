<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeAssignmentHistory;
use App\Models\EmployeeEmploymentHistory;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_directory_filters_and_rejects_inactive_office_heads(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $activeHead = User::factory()->create(['role' => 'office_head', 'is_active' => true]);
        $inactiveHead = User::factory()->create(['role' => 'office_head', 'is_active' => false]);
        $department = Department::create([
            'name' => 'Finance Office',
            'code' => 'FIN',
            'head_user_id' => $activeHead->id,
        ]);
        Department::create(['name' => 'Parks Office', 'code' => 'PRK', 'is_active' => false]);

        $this->actingAs($admin)
            ->post(route('departments.store'), [
                'name' => 'New Office',
                'code' => 'NEW',
                'head_user_id' => $inactiveHead->id,
            ])
            ->assertSessionHasErrors('head_user_id');

        $this->put(route('departments.update', $department), [
            'name' => $department->name,
            'code' => $department->code,
            'head_user_id' => $inactiveHead->id,
            'is_active' => '1',
        ])->assertSessionHasErrors('head_user_id');
        $this->assertSame($activeHead->id, $department->fresh()->head_user_id);

        $this->get(route('departments.index', ['q' => 'Finance', 'status' => 'active']))
            ->assertOk()
            ->assertSee('Finance Office')
            ->assertDontSee('Parks Office')
            ->assertViewHas('departments', fn ($departments) => $departments->total() === 1);

        $this->actingAs($activeHead)
            ->get(route('departments.index'))
            ->assertOk()
            ->assertDontSee('Add department')
            ->assertDontSee('Save changes');
        $this->post(route('departments.store'))->assertForbidden();
        $this->put(route('departments.update', $department))->assertForbidden();
        $this->get(route('positions.index'))->assertForbidden();
    }

    public function test_position_directory_filters_by_search_department_salary_grade_and_status(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $finance = Department::create(['name' => 'Finance Office', 'code' => 'FIN']);
        $parks = Department::create(['name' => 'Parks Office', 'code' => 'PRK']);
        Position::create([
            'department_id' => $finance->id,
            'title' => 'Finance Clerk',
            'salary_grade' => '15',
            'is_active' => true,
        ]);
        Position::create([
            'department_id' => $finance->id,
            'title' => 'Finance Clerk II',
            'salary_grade' => '15',
            'is_active' => false,
        ]);
        Position::create([
            'department_id' => $parks->id,
            'title' => 'Parks Clerk',
            'salary_grade' => '16',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('positions.index', [
                'q' => 'Clerk',
                'department_id' => $finance->id,
                'salary_grade' => '15',
                'status' => 'active',
            ]))
            ->assertOk()
            ->assertSee('Finance Clerk')
            ->assertDontSee('Finance Clerk II')
            ->assertDontSee('Parks Clerk')
            ->assertViewHas('positions', fn ($positions) => $positions->total() === 1);
    }

    public function test_positions_reject_duplicate_titles_and_new_assignments_to_inactive_departments(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $activeDepartment = Department::create(['name' => 'Active Office', 'code' => 'ACT']);
        $inactiveDepartment = Department::create([
            'name' => 'Inactive Office',
            'code' => 'INA',
            'is_active' => false,
        ]);
        Position::create([
            'department_id' => $activeDepartment->id,
            'title' => 'Records Officer',
        ]);

        $this->actingAs($admin)
            ->post(route('positions.store'), [
                'department_id' => $activeDepartment->id,
                'title' => 'Records Officer',
            ])
            ->assertSessionHasErrors('title');

        $this->post(route('positions.store'), [
            'department_id' => $inactiveDepartment->id,
            'title' => 'New Position',
        ])->assertSessionHasErrors('department_id');

        $this->assertDatabaseMissing('positions', ['title' => 'New Position']);
    }

    public function test_employee_assignment_changes_are_recorded_without_replacing_imported_appointment_history(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $previousDepartment = Department::create(['name' => 'Previous Office', 'code' => 'PREV']);
        $currentDepartment = Department::create(['name' => 'Current Office', 'code' => 'CURR']);
        $previousPosition = Position::create([
            'department_id' => $previousDepartment->id,
            'title' => 'Previous Position',
        ]);
        $currentPosition = Position::create([
            'department_id' => $currentDepartment->id,
            'title' => 'Current Position',
        ]);

        $this->actingAs($admin)->post(route('employees.store'), [
            'employee_no' => 'HIST-001',
            'department_id' => $previousDepartment->id,
            'position_id' => $previousPosition->id,
            'first_name' => 'History',
            'last_name' => 'Employee',
            'employment_status' => 'Active',
            'date_hired' => '2020-01-06',
        ])->assertRedirect();

        $employee = Employee::where('employee_no', 'HIST-001')->firstOrFail();
        $initialHistory = EmployeeAssignmentHistory::where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('initial', $initialHistory->change_type);
        $this->assertSame($previousDepartment->id, $initialHistory->department_id);
        $this->assertSame($previousPosition->id, $initialHistory->position_id);
        $this->assertSame('2020-01-06', $initialHistory->effective_date->toDateString());
        EmployeeEmploymentHistory::create([
            'employee_id' => $employee->id,
            'position_title' => 'Imported Appointment',
            'sequence' => 1,
        ]);

        $this->put(route('employees.update', $employee), [
            'employee_no' => $employee->employee_no,
            'department_id' => $currentDepartment->id,
            'position_id' => $currentPosition->id,
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'employment_status' => $employee->employment_status,
            'date_hired' => $employee->date_hired->toDateString(),
            'assignment_effective_date' => '2026-10-01',
            'assignment_change_reason' => 'Approved reassignment.',
        ])->assertRedirect(route('employees.show', $employee));

        $this->assertDatabaseHas('employee_assignment_histories', [
            'employee_id' => $employee->id,
            'change_type' => 'updated',
            'previous_department_id' => $previousDepartment->id,
            'previous_department_name' => $previousDepartment->name,
            'previous_position_id' => $previousPosition->id,
            'previous_position_title' => $previousPosition->title,
            'department_id' => $currentDepartment->id,
            'position_id' => $currentPosition->id,
            'changed_by' => $admin->id,
            'reason' => 'Approved reassignment.',
        ]);
        $updatedHistory = EmployeeAssignmentHistory::where('employee_id', $employee->id)
            ->where('change_type', 'updated')
            ->firstOrFail();
        $this->assertSame('2026-10-01', $updatedHistory->effective_date->toDateString());
        $this->assertDatabaseHas('employee_employment_histories', [
            'employee_id' => $employee->id,
            'position_title' => 'Imported Appointment',
        ]);

        $this->get(route('employees.show', $employee))
            ->assertOk()
            ->assertSee('Department and position assignments')
            ->assertSee('Approved reassignment.');
        $this->assertSame(2, EmployeeAssignmentHistory::where('employee_id', $employee->id)->count());

        $employeeUser = User::factory()->create(['role' => 'employee', 'is_active' => true]);
        $employee->update(['user_id' => $employeeUser->id]);
        $this->actingAs($employeeUser)
            ->get(route('employees.show', $employee))
            ->assertOk()
            ->assertDontSee('Department and position assignments')
            ->assertDontSee('Approved reassignment.');
    }
}
