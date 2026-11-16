<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class HrisScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_salary_deduction_monitoring_routes_and_attendance_relation_are_not_exposed(): void
    {
        $this->assertFalse(Route::has('deductions.index'));
        $this->assertFalse(Route::has('deductions.review'));
        $this->assertFalse(Route::has('deductions.approve'));
        $this->assertFalse(method_exists(AttendanceRecord::class, 'deductionRecord'));

        $this->get('/deductions')->assertNotFound();
        $this->post('/deductions/attendance/1/review')->assertNotFound();
        $this->post('/deductions/1/approve')->assertNotFound();
    }

    public function test_every_role_dashboard_and_navigation_remain_available_without_deduction_content(): void
    {
        $department = Department::create(['name' => 'Scope Test Department']);
        $accounts = collect(['admin', 'mayor', 'office_head', 'employee'])
            ->mapWithKeys(fn (string $role) => [
                $role => User::factory()->create([
                    'name' => ucfirst($role).' User',
                    'role' => $role,
                    'is_active' => true,
                ]),
            ]);
        $department->update(['head_user_id' => $accounts['office_head']->id]);
        Employee::create([
            'user_id' => $accounts['employee']->id,
            'department_id' => $department->id,
            'employee_no' => 'SCOPE-EMP-001',
            'first_name' => 'Scope',
            'last_name' => 'Employee',
            'employment_status' => 'Active',
        ]);

        foreach ($accounts as $account) {
            $response = $this->actingAs($account)->get(route('dashboard'))->assertOk();
            $this->assertStringNotContainsString('deduction', strtolower($response->getContent()));
        }
    }
}
