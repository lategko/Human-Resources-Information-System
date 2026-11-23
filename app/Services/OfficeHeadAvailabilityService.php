<?php

namespace App\Services;

use App\Models\Department;
use App\Models\LeaveApplication;
use App\Models\LeaveApprovalHistory;
use App\Models\OfficeHeadAvailability;
use App\Models\OfficeHeadAvailabilityHistory;
use App\Models\User;
use App\Notifications\LeaveApplicationUpdated;
use App\Notifications\OfficeHeadAvailabilityUpdated;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OfficeHeadAvailabilityService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function record(array $data, User $actor): OfficeHeadAvailability
    {
        return DB::transaction(function () use ($data, $actor) {
            $department = Department::query()->lockForUpdate()->findOrFail($data['department_id']);
            $head = $department->head_user_id
                ? User::query()->lockForUpdate()->find($department->head_user_id)
                : null;

            abort_unless(
                $head?->is_active
                    && $head->isOfficeHead()
                    && ($actor->isAdministrator() || $head->id === $actor->id),
                422,
                'The department must have an active Office Head, and Office Heads may record availability only for their own department.'
            );

            $alternate = null;
            if (! empty($data['alternate_user_id'])) {
                $alternate = User::query()->lockForUpdate()->findOrFail($data['alternate_user_id']);
                abort_unless(
                    $alternate->is_active && ($alternate->isOfficeHead() || $alternate->isMayor()),
                    422,
                    'Choose an active Office Head or the Mayor as the alternate.'
                );
                abort_if(
                    $alternate->id === $head->id,
                    422,
                    'Choose a different user as the temporary alternate.'
                );
            }

            $overlap = OfficeHeadAvailability::query()
                ->where('department_id', $department->id)
                ->where('office_head_user_id', $head->id)
                ->whereNull('cancelled_at')
                ->whereDate('start_date', '<=', $data['end_date'])
                ->whereDate('end_date', '>=', $data['start_date'])
                ->exists();
            if ($overlap) {
                throw ValidationException::withMessages([
                    'start_date' => 'This period overlaps an existing availability record for the department Office Head.',
                ]);
            }

            $availability = OfficeHeadAvailability::create([
                ...$data,
                'office_head_user_id' => $head->id,
                'updated_by' => $actor->id,
            ]);
            $this->recordHistory($availability, 'created', null, $this->state($availability), $actor, 'Availability period recorded.');

            if ($availability->availability_status !== 'available') {
                $this->reroutePendingApplications($availability, $head, $alternate, $actor);
            }

            $availability->load('department');
            $head->notify(new OfficeHeadAvailabilityUpdated(
                $availability,
                'Your Office Head availability period for '.$department->name.' was recorded.'
            ));

            $availabilityAlternate = $alternate
                ?? ($availability->availability_status !== 'available' ? $this->singleActiveMayor() : null);
            if ($availabilityAlternate && $availabilityAlternate->id !== $head->id) {
                $availabilityAlternate->notify(new OfficeHeadAvailabilityUpdated(
                    $availability,
                    'You are designated as the temporary alternate for '.$department->name.' during the recorded availability period.'
                ));
            }

            $this->auditLogger->record('office_head.availability_recorded', $availability, [
                'department_id' => $availability->department_id,
                'availability_status' => $availability->availability_status,
                'start_date' => $availability->start_date->toDateString(),
                'end_date' => $availability->end_date->toDateString(),
                'alternate_user_id' => $availability->alternate_user_id,
            ]);

            return $availability;
        });
    }

    public function update(OfficeHeadAvailability $availability, array $data, User $actor): OfficeHeadAvailability
    {
        return DB::transaction(function () use ($availability, $data, $actor) {
            $availability = OfficeHeadAvailability::query()->lockForUpdate()->findOrFail($availability->id);
            abort_if($availability->cancelled_at, 409, 'Cancelled availability records are immutable.');

            $department = Department::query()->lockForUpdate()->findOrFail($availability->department_id);
            $head = User::query()->lockForUpdate()->find($availability->office_head_user_id);
            abort_unless($head?->isOfficeHead(), 409, 'This availability record has no valid Office Head.');

            $alternate = null;
            if (! empty($data['alternate_user_id'])) {
                $alternate = User::query()->lockForUpdate()->findOrFail($data['alternate_user_id']);
                abort_unless(
                    $alternate->is_active && ($alternate->isOfficeHead() || $alternate->isMayor()),
                    422,
                    'Choose an active Office Head or the Mayor as the alternate.'
                );
                abort_if($alternate->id === $head->id, 422, 'Choose a different user as the temporary alternate.');
            }
            if ($data['availability_status'] === 'available' && $alternate) {
                abort(422, 'An alternate approver can only be assigned while the Office Head is unavailable.');
            }

            $overlap = OfficeHeadAvailability::query()
                ->where('department_id', $availability->department_id)
                ->where('office_head_user_id', $availability->office_head_user_id)
                ->whereNull('cancelled_at')
                ->whereKeyNot($availability->id)
                ->whereDate('start_date', '<=', $data['end_date'])
                ->whereDate('end_date', '>=', $data['start_date'])
                ->exists();
            if ($overlap) {
                throw ValidationException::withMessages([
                    'start_date' => 'This period overlaps another active availability record for the department Office Head.',
                ]);
            }

            $before = $this->state($availability);
            $oldAlternate = $availability->alternate
                ?? ($availability->availability_status !== 'available' ? $this->singleActiveMayor() : null);
            $availability->fill([
                ...$data,
                'updated_by' => $actor->id,
            ]);
            $availability->save();
            $this->recordHistory($availability, 'updated', $before, $this->state($availability), $actor, 'Availability period updated.');

            if ($availability->availability_status !== 'available') {
                $this->reroutePendingApplications($availability, $head, $alternate, $actor);
            }

            $availability->load('department');
            $notificationAlternate = $alternate
                ?? ($availability->availability_status !== 'available' ? $this->singleActiveMayor() : null);
            $this->notifyAvailabilityParties(
                $availability,
                $head,
                $oldAlternate,
                $notificationAlternate,
                'The Office Head availability period for '.$department->name.' was updated. Applications already assigned to an alternate remain with that reviewer.'
            );
            $this->notifyApplicationsRoutedByAvailability(
                $availability,
                'The Office Head availability period changed. Your application remains with its currently assigned reviewer.'
            );
            $this->auditLogger->record('office_head.availability_updated', $availability, [
                'before' => $before,
                'after' => $this->state($availability),
            ]);

            return $availability;
        });
    }

    public function cancel(OfficeHeadAvailability $availability, string $reason, User $actor): OfficeHeadAvailability
    {
        return DB::transaction(function () use ($availability, $reason, $actor) {
            $availability = OfficeHeadAvailability::query()->lockForUpdate()->findOrFail($availability->id);
            abort_if($availability->cancelled_at, 409, 'This availability period has already been cancelled.');

            $before = $this->state($availability);
            $availability->update([
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancellation_reason' => $reason,
                'updated_by' => $actor->id,
            ]);
            $after = [...$before, 'cancelled_at' => $availability->cancelled_at->toIso8601String()];
            $this->recordHistory($availability, 'cancelled', $before, $after, $actor, $reason);

            $availability->load('department');
            $head = $availability->officeHead;
            $alternate = $availability->alternate
                ?? ($availability->availability_status !== 'available' ? $this->singleActiveMayor() : null);
            $this->notifyAvailabilityParties(
                $availability,
                $head,
                $alternate,
                null,
                'The Office Head availability period for '.$availability->department->name.' was cancelled. Applications already assigned to an alternate remain with that reviewer.'
            );
            $this->notifyApplicationsRoutedByAvailability(
                $availability,
                'The Office Head availability period was cancelled. Your application remains with its currently assigned reviewer; no automatic reassignment was made.'
            );
            $this->auditLogger->record('office_head.availability_cancelled', $availability, [
                'before' => $before,
                'after' => $after,
                'cancellation_reason' => $reason,
            ]);

            return $availability;
        });
    }

    private function reroutePendingApplications(
        OfficeHeadAvailability $availability,
        User $head,
        ?User $alternate,
        User $actor
    ): void {
        $applications = LeaveApplication::query()
            ->with('employee')
            ->where('office_head_reviewer_id', $head->id)
            ->whereIn('status', ['Under Office Head Review', 'Under Alternate Office Head Review'])
            ->whereDate('start_date', '<=', $availability->end_date)
            ->whereDate('end_date', '>=', $availability->start_date)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($applications as $application) {
            $delegate = $this->delegateFor($alternate, $application);
            if (! $delegate) {
                throw ValidationException::withMessages([
                    'alternate_user_id' => 'An eligible alternate is required because pending leave applications overlap this period. Choose an active Office Head or Mayor who is not an applicant, or ensure there is exactly one active Mayor.',
                ]);
            }

            $previousStatus = $application->status;
            $application->office_head_reviewer_id = $delegate->id;
            $application->office_head_routing_reason = ucfirst(str_replace('_', ' ', $availability->availability_status)).': '.$availability->reason;
            $application->status = 'Under Alternate Office Head Review';
            $application->save();

            LeaveApprovalHistory::create([
                'leave_application_id' => $application->id,
                'user_id' => $actor->id,
                'role' => $actor->role,
                'action' => 'office_head_availability_rerouted',
                'from_status' => $previousStatus,
                'to_status' => $application->status,
                'remarks' => 'Rerouted to an authorized alternate under availability record #'.$availability->id.'. '.$availability->reason,
            ]);

            $this->auditLogger->record('leave.office_head_availability_rerouted', $application, [
                'availability_id' => $availability->id,
                'previous_reviewer_id' => $head->id,
                'new_reviewer_id' => $delegate->id,
            ]);

            $application->employee?->user?->notify(new LeaveApplicationUpdated(
                $application,
                'Your leave application #'.$application->id.' was reassigned to an authorized alternate Office Head reviewer.'
            ));
            $delegate->notify(new LeaveApplicationUpdated(
                $application,
                'Leave application #'.$application->id.' was assigned to you as an authorized alternate reviewer.'
            ));
        }
    }

    private function delegateFor(?User $alternate, LeaveApplication $application): ?User
    {
        $applicantUserId = $application->employee?->user_id;
        if ($alternate && $alternate->is_active && ($alternate->isOfficeHead() || $alternate->isMayor())
            && $alternate->id !== $applicantUserId) {
            return $alternate;
        }

        $mayor = $this->singleActiveMayor();

        return $mayor && $mayor->id !== $applicantUserId ? $mayor : null;
    }

    private function singleActiveMayor(): ?User
    {
        $mayors = User::query()
            ->where('role', 'mayor')
            ->where('is_active', true)
            ->orderBy('id')
            ->limit(2)
            ->lockForUpdate()
            ->get();

        return $mayors->count() === 1 ? $mayors->first() : null;
    }

    private function recordHistory(
        OfficeHeadAvailability $availability,
        string $action,
        ?array $before,
        ?array $after,
        User $actor,
        string $note
    ): void {
        OfficeHeadAvailabilityHistory::create([
            'availability_id' => $availability->id,
            'actor_id' => $actor->id,
            'action' => $action,
            'before_state' => $before,
            'after_state' => $after,
            'note' => $note,
        ]);
    }

    private function state(OfficeHeadAvailability $availability): array
    {
        return [
            'availability_status' => $availability->availability_status,
            'start_date' => $availability->start_date->toDateString(),
            'end_date' => $availability->end_date->toDateString(),
            'reason' => $availability->reason,
            'alternate_user_id' => $availability->alternate_user_id,
        ];
    }

    private function notifyAvailabilityParties(
        OfficeHeadAvailability $availability,
        ?User $head,
        ?User $oldAlternate,
        ?User $newAlternate,
        string $message
    ): void {
        $recipients = collect([$head, $oldAlternate, $newAlternate])
            ->filter()
            ->unique('id');
        foreach ($recipients as $recipient) {
            $recipient->notify(new OfficeHeadAvailabilityUpdated($availability, $message));
        }
    }

    private function notifyApplicationsRoutedByAvailability(
        OfficeHeadAvailability $availability,
        string $message
    ): void {
        $applicationIds = LeaveApprovalHistory::query()
            ->where('action', 'office_head_availability_rerouted')
            ->where('remarks', 'like', '%availability record #'.$availability->id.'.%')
            ->pluck('leave_application_id');
        if ($applicationIds->isEmpty()) {
            return;
        }

        $applications = LeaveApplication::query()
            ->with(['employee.user', 'officeHeadReviewer'])
            ->whereIn('id', $applicationIds)
            ->whereIn('status', ['Under Alternate Office Head Review'])
            ->get();
        foreach ($applications as $application) {
            $application->employee?->user?->notify(new LeaveApplicationUpdated($application, $message));
            $application->officeHeadReviewer?->notify(new LeaveApplicationUpdated($application, $message));
        }
    }
}
