<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DatabaseBackupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SystemSettingsController extends Controller
{
    public function index(DatabaseBackupService $backupService): View
    {
        return view('settings.system', [
            'workweekDays' => SystemSetting::find('workweek_days')?->value ?? [1, 2, 3, 4, 5],
            'alternateHrReviewerId' => SystemSetting::find('leave_alternate_hr_reviewer_id')?->value,
            'mayorLeaveApproverId' => SystemSetting::find('mayor_leave_approver_id')?->value,
            'hrReviewers' => User::query()->where('role', 'admin')->where('is_active', true)->orderBy('name')->get(),
            'designatedApprovers' => User::query()->where('role', 'admin')->where('can_final_approve', true)
                ->where('is_active', true)->orderBy('name')->get(),
            'databaseBackups' => $backupService->history(),
        ]);
    }

    public function update(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'workweek_days' => ['required', 'array', 'min:1', 'max:7'],
            'workweek_days.*' => ['required', 'integer', 'between:1,7', 'distinct'],
            'alternate_hr_reviewer_id' => ['nullable', 'integer', 'exists:users,id'],
            'mayor_leave_approver_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        if (! empty($data['alternate_hr_reviewer_id'])) {
            $reviewer = User::findOrFail($data['alternate_hr_reviewer_id']);
            abort_unless($reviewer->is_active && $reviewer->isAdministrator(), 422, 'The alternate HR reviewer must be an active Admin (HR Office) account.');
        }
        if (! empty($data['mayor_leave_approver_id'])) {
            $approver = User::findOrFail($data['mayor_leave_approver_id']);
            abort_unless($approver->canApproveMayorLeave(), 422, 'The Mayor leave approver must be an active Admin explicitly designated for this permission.');
        }
        SystemSetting::updateOrCreate(['key' => 'workweek_days'], [
            'value' => array_values(array_map('intval', $data['workweek_days'])),
            'updated_by' => $request->user()->id,
        ]);
        foreach ([
            'leave_alternate_hr_reviewer_id' => $data['alternate_hr_reviewer_id'] ?? null,
            'mayor_leave_approver_id' => $data['mayor_leave_approver_id'] ?? null,
        ] as $key => $value) {
            SystemSetting::updateOrCreate(['key' => $key], [
                'value' => $value === null ? null : (int) $value,
                'updated_by' => $request->user()->id,
            ]);
        }
        $auditLogger->record('settings.workweek_updated', null, ['workweek_days' => $data['workweek_days']]);
        $auditLogger->record('settings.leave_reviewers_updated', null, [
            'alternate_hr_reviewer_id' => $data['alternate_hr_reviewer_id'] ?? null,
            'mayor_leave_approver_id' => $data['mayor_leave_approver_id'] ?? null,
        ]);

        return back()->with('success', 'Workweek and leave approval assignments updated.');
    }
}
