<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\LeaveType;
use App\Models\OfficeHeadAvailability;
use App\Models\OfficeHeadAvailabilityHistory;
use App\Models\Position;
use App\Models\User;
use App\Notifications\LeaveApplicationUpdated;
use App\Notifications\OfficeHeadAvailabilityUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfficeHeadAvailabilityRoutingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $head;

    private User $alternate;

    private User $applicant;

    private User $mayor;

    private Department $department;

    private LeaveType $leaveType;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = $this->makeUser('admin', 'availability-admin');
        $this->head = $this->makeUser('office_head', 'availability-head');
        $this->alternate = $this->makeUser('office_head', 'availability-alternate');
        $this->mayor = $this->makeUser('mayor', 'availability-mayor');
        $this->applicant = $this->makeUser('employee', 'availability-applicant');

        $this->department = Department::create([
            'name' => 'Availability Test Department',
            'code' => 'AVAIL-TEST',
            'head_user_id' => $this->head->id,
        ]);
        $position = Position::create([
            'department_id' => $this->department->id,
            'title' => 'Availability Test Position',
        ]);
        Employee::create([
            'user_id' => $this->applicant->id,
            'department_id' => $this->department->id,
            'position_id' => $position->id,
            'employee_no' => 'AVAIL-TEST-001',
            'first_name' => 'Availability',
            'last_name' => 'Applicant',
            'employment_status' => 'Active',
            'employment_type' => 'Permanent',
            'date_hired' => '2020-01-06',
        ]);
        $this->leaveType = LeaveType::where('code', 'VACATION')->firstOrFail();
    }

    public function test_recording_unavailability_reroutes_only_overlapping_pending_reviews_and_notifies_users(): void
    {
        $startDate = today()->addDays(5)->toDateString();
        $endDate = today()->addDays(7)->toDateString();
        $application = $this->createPendingApplication($startDate, today()->addDays(6)->toDateString());
        $unrelatedApplication = $this->createPendingApplication(today()->addDays(10)->toDateString(), today()->addDays(11)->toDateString());

        $this->actingAs($this->admin)->post(route('office-head-availability.store'), [
            'department_id' => $this->department->id,
            'availability_status' => 'on_leave',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'reason' => 'Approved leave period.',
            'alternate_user_id' => $this->alternate->id,
        ])->assertRedirect();

        $application->refresh();
        $this->assertSame('Under Alternate Office Head Review', $application->status);
        $this->assertSame($this->alternate->id, $application->office_head_reviewer_id);
        $this->assertStringContainsString('Approved leave period.', $application->office_head_routing_reason);
        $this->assertSame('Under Office Head Review', $unrelatedApplication->fresh()->status);
        $this->assertSame($this->head->id, $unrelatedApplication->fresh()->office_head_reviewer_id);
        $this->assertDatabaseHas('leave_approval_histories', [
            'leave_application_id' => $application->id,
            'user_id' => $this->admin->id,
            'action' => 'office_head_availability_rerouted',
            'from_status' => 'Under Office Head Review',
            'to_status' => 'Under Alternate Office Head Review',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'leave.office_head_availability_rerouted',
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->applicant->id,
            'type' => LeaveApplicationUpdated::class,
        ]);
        $this->assertTrue($this->alternate->notifications()->where('data->application_id', $application->id)->exists());
        $this->assertTrue($this->alternate->notifications()->where('type', OfficeHeadAvailabilityUpdated::class)->exists());
        $this->assertSame('Under Alternate Office Head Review', $application->fresh()->status);

        $availabilityNotification = $this->head->notifications()
            ->where('type', OfficeHeadAvailabilityUpdated::class)
            ->firstOrFail();
        $this->actingAs($this->head)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Office Head availability')
            ->assertSee('Open availability records');
        $this->actingAs($this->head)
            ->postJson(route('notifications.read', $availabilityNotification->id))
            ->assertOk()
            ->assertJsonPath('redirect_url', route('office-head-availability.index'));
        $this->actingAs($this->alternate)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Alternate Office Head review')
            ->assertSee('Open application');
    }

    public function test_unavailability_is_not_recorded_if_pending_applications_have_no_eligible_alternate(): void
    {
        $this->mayor->update(['is_active' => false]);
        $this->createPendingApplication(today()->addDays(5)->toDateString(), today()->addDays(6)->toDateString());

        $this->actingAs($this->admin)->post(route('office-head-availability.store'), [
            'department_id' => $this->department->id,
            'availability_status' => 'on_leave',
            'start_date' => today()->addDays(5)->toDateString(),
            'end_date' => today()->addDays(7)->toDateString(),
            'reason' => 'Approved leave period.',
        ])->assertSessionHasErrors('alternate_user_id');

        $this->assertDatabaseCount('office_head_availabilities', 0);
        $this->assertDatabaseCount('leave_approval_histories', 0);
        $this->assertSame(
            'Under Office Head Review',
            LeaveApplication::firstOrFail()->status
        );
    }

    public function test_unique_active_mayor_can_receive_pending_reviews_when_no_alternate_is_selected(): void
    {
        $application = $this->createPendingApplication(today()->addDays(5)->toDateString(), today()->addDays(6)->toDateString());

        $this->actingAs($this->admin)->post(route('office-head-availability.store'), [
            'department_id' => $this->department->id,
            'availability_status' => 'official_business',
            'start_date' => today()->addDays(5)->toDateString(),
            'end_date' => today()->addDays(7)->toDateString(),
            'reason' => 'Official business period.',
        ])->assertRedirect();

        $application->refresh();
        $this->assertSame('Under Alternate Office Head Review', $application->status);
        $this->assertSame($this->mayor->id, $application->office_head_reviewer_id);
    }

    public function test_available_period_does_not_notify_or_designate_a_backup_approver(): void
    {
        $this->actingAs($this->admin)->post(route('office-head-availability.store'), [
            'department_id' => $this->department->id,
            'availability_status' => 'available',
            'start_date' => today()->addDays(5)->toDateString(),
            'end_date' => today()->addDays(7)->toDateString(),
            'reason' => 'Available for scheduled reviews.',
        ])->assertRedirect();

        $this->assertTrue($this->head->notifications()->where('type', OfficeHeadAvailabilityUpdated::class)->exists());
        $this->assertFalse($this->mayor->notifications()->where('type', OfficeHeadAvailabilityUpdated::class)->exists());
    }

    public function test_only_authorized_roles_can_record_availability(): void
    {
        foreach ([$this->applicant, $this->mayor] as $unauthorizedUser) {
            $this->actingAs($unauthorizedUser)->post(route('office-head-availability.store'), [
                'department_id' => $this->department->id,
                'availability_status' => 'on_leave',
                'start_date' => today()->addDays(5)->toDateString(),
                'end_date' => today()->addDays(7)->toDateString(),
                'reason' => 'Approved leave period.',
            ])->assertForbidden();
        }
    }

    public function test_office_head_can_record_only_their_own_department_availability(): void
    {
        $this->actingAs($this->head)->post(route('office-head-availability.store'), [
            'department_id' => $this->department->id,
            'availability_status' => 'available',
            'start_date' => today()->addDays(5)->toDateString(),
            'end_date' => today()->addDays(7)->toDateString(),
            'reason' => 'Available for scheduled reviews.',
        ])->assertRedirect();

        $this->assertDatabaseHas('office_head_availabilities', [
            'department_id' => $this->department->id,
            'office_head_user_id' => $this->head->id,
        ]);
    }

    public function test_admin_edits_and_cancels_availability_without_reversing_routed_applications(): void
    {
        $applicationStart = today()->addDays(5)->toDateString();
        $application = $this->createPendingApplication($applicationStart, today()->addDays(6)->toDateString());
        $this->actingAs($this->admin)->post(route('office-head-availability.store'), [
            'department_id' => $this->department->id,
            'availability_status' => 'on_leave',
            'start_date' => today()->addDays(10)->toDateString(),
            'end_date' => today()->addDays(12)->toDateString(),
            'reason' => 'Original dates.',
            'alternate_user_id' => $this->alternate->id,
        ])->assertRedirect();
        $availability = OfficeHeadAvailability::firstOrFail();

        $this->actingAs($this->admin)->put(route('office-head-availability.update', $availability), [
            'availability_status' => 'official_business',
            'start_date' => $applicationStart,
            'end_date' => today()->addDays(7)->toDateString(),
            'reason' => 'Updated dates.',
            'alternate_user_id' => $this->alternate->id,
        ])->assertRedirect();

        $this->assertSame('Under Alternate Office Head Review', $application->fresh()->status);
        $this->assertSame($this->alternate->id, $application->fresh()->office_head_reviewer_id);
        $this->assertSame(2, $availability->fresh()->history()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'office_head.availability_updated']);

        $this->actingAs($this->admin)->post(route('office-head-availability.cancel', $availability), [
            'cancellation_reason' => 'The recorded period was entered in error.',
        ])->assertRedirect();

        $availability->refresh();
        $this->assertNotNull($availability->cancelled_at);
        $this->assertSame('The recorded period was entered in error.', $availability->cancellation_reason);
        $this->assertSame(3, $availability->history()->count());
        $this->assertSame('Under Alternate Office Head Review', $application->fresh()->status);
        $this->assertSame($this->alternate->id, $application->fresh()->office_head_reviewer_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'office_head.availability_cancelled']);
        $this->assertTrue($this->applicant->notifications()
            ->where('type', LeaveApplicationUpdated::class)
            ->where('data->message', 'like', '%availability period was cancelled%')
            ->exists());

        $this->actingAs($this->admin)->post(route('office-head-availability.store'), [
            'department_id' => $this->department->id,
            'availability_status' => 'on_leave',
            'start_date' => $applicationStart,
            'end_date' => today()->addDays(7)->toDateString(),
            'reason' => 'Replacement availability period.',
            'alternate_user_id' => $this->alternate->id,
        ])->assertRedirect();
        $this->assertDatabaseCount('office_head_availabilities', 2);
    }

    public function test_edit_rejects_conflicting_active_periods_without_changing_history(): void
    {
        $this->actingAs($this->admin)->post(route('office-head-availability.store'), [
            'department_id' => $this->department->id,
            'availability_status' => 'on_leave',
            'start_date' => today()->addDays(5)->toDateString(),
            'end_date' => today()->addDays(6)->toDateString(),
            'reason' => 'First period.',
            'alternate_user_id' => $this->alternate->id,
        ])->assertRedirect();
        $this->actingAs($this->admin)->post(route('office-head-availability.store'), [
            'department_id' => $this->department->id,
            'availability_status' => 'official_business',
            'start_date' => today()->addDays(10)->toDateString(),
            'end_date' => today()->addDays(11)->toDateString(),
            'reason' => 'Second period.',
            'alternate_user_id' => $this->alternate->id,
        ])->assertRedirect();
        $availability = OfficeHeadAvailability::orderBy('id')->firstOrFail();

        $this->actingAs($this->admin)->put(route('office-head-availability.update', $availability), [
            'availability_status' => 'on_leave',
            'start_date' => today()->addDays(10)->toDateString(),
            'end_date' => today()->addDays(12)->toDateString(),
            'reason' => 'Conflicting dates.',
            'alternate_user_id' => $this->alternate->id,
        ])->assertSessionHasErrors('start_date');

        $this->assertSame(today()->addDays(5)->toDateString(), $availability->fresh()->start_date->toDateString());
        $this->assertSame(2, OfficeHeadAvailabilityHistory::query()->count());
    }

    public function test_only_admin_can_edit_or_cancel_availability(): void
    {
        $this->actingAs($this->admin)->post(route('office-head-availability.store'), [
            'department_id' => $this->department->id,
            'availability_status' => 'on_leave',
            'start_date' => today()->addDays(5)->toDateString(),
            'end_date' => today()->addDays(6)->toDateString(),
            'reason' => 'Admin-managed period.',
            'alternate_user_id' => $this->alternate->id,
        ])->assertRedirect();
        $availability = OfficeHeadAvailability::firstOrFail();
        $update = [
            'availability_status' => 'on_leave',
            'start_date' => today()->addDays(5)->toDateString(),
            'end_date' => today()->addDays(6)->toDateString(),
            'reason' => 'Attempted edit.',
        ];

        foreach ([$this->head, $this->mayor, $this->applicant] as $unauthorizedUser) {
            $this->actingAs($unauthorizedUser)
                ->put(route('office-head-availability.update', $availability), $update)
                ->assertForbidden();
            $this->actingAs($unauthorizedUser)
                ->post(route('office-head-availability.cancel', $availability), [
                    'cancellation_reason' => 'Attempted cancellation.',
                ])
                ->assertForbidden();
        }

        $this->assertSame(1, $availability->fresh()->history()->count());
        $this->assertNull($availability->fresh()->cancelled_at);
    }

    private function makeUser(string $role, string $prefix): User
    {
        return User::create([
            'name' => ucfirst($prefix),
            'email' => $prefix.'@example.test',
            'password' => 'TestAccount123!',
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function createPendingApplication(string $startDate, string $endDate): LeaveApplication
    {
        $employee = Employee::where('user_id', $this->applicant->id)->firstOrFail();

        return LeaveApplication::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $this->leaveType->id,
            'status' => 'Under Office Head Review',
            'office_head_reviewer_id' => $this->head->id,
            'office_snapshot' => $this->department->name,
            'last_name_snapshot' => 'Applicant',
            'first_name_snapshot' => 'Availability',
            'working_days' => 1,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
    }
}
