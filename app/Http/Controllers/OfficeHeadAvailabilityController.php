<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\OfficeHeadAvailability;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OfficeHeadAvailabilityController extends Controller
{
    public function index(): View
    {
        return view('office-head-availability.index', [
            'departments' => Department::with('head')->whereNotNull('head_user_id')->orderBy('name')->get(),
            'availabilityRecords' => OfficeHeadAvailability::with(['department', 'officeHead', 'alternate', 'updater'])
                ->latest('start_date')
                ->paginate(20),
            'alternates' => User::query()->where('is_active', true)
                ->where(function ($query) {
                    $query->where('role', 'office_head')
                        ->orWhere('role', 'mayor')
                        ->orWhere(fn ($approvers) => $approvers->where('role', 'admin')->where('can_final_approve', true));
                })
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->whereNotNull('head_user_id')],
            'availability_status' => ['required', Rule::in(['available', 'temporarily_unavailable', 'on_leave', 'official_business', 'unable_to_approve'])],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['required', 'string', 'max:2000'],
            'alternate_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $alternate = null;
        if (! empty($data['alternate_user_id'])) {
            $alternate = User::findOrFail($data['alternate_user_id']);
            abort_unless(
                $alternate->is_active && ($alternate->isOfficeHead() || $alternate->canFinalApprove()),
                422,
                'Choose an active Office Head or designated final approver as the alternate.'
            );
        }

        if ($data['availability_status'] === 'available' && $alternate) {
            throw ValidationException::withMessages([
                'alternate_user_id' => 'An alternate approver can only be assigned while the Office Head is unavailable.',
            ]);
        }

        $record = DB::transaction(function () use ($data, $request, $alternate) {
            $department = Department::query()->lockForUpdate()->with('head')->findOrFail($data['department_id']);
            abort_unless($department->head?->isOfficeHead(), 422, 'The department must have a valid assigned Office Head.');
            abort_if(
                $alternate && $alternate->id === $department->head_user_id,
                422,
                'Choose a different user as the temporary alternate.'
            );
            $overlap = OfficeHeadAvailability::query()
                ->where('department_id', $department->id)
                ->where('office_head_user_id', $department->head_user_id)
                ->whereDate('start_date', '<=', $data['end_date'])
                ->whereDate('end_date', '>=', $data['start_date'])
                ->exists();
            if ($overlap) {
                throw ValidationException::withMessages([
                    'start_date' => 'This period overlaps an existing availability record for the department Office Head.',
                ]);
            }

            return OfficeHeadAvailability::create([
                ...$data,
                'office_head_user_id' => $department->head_user_id,
                'updated_by' => $request->user()->id,
            ]);
        });
        $auditLogger->record('office_head.availability_recorded', $record, [
            'department_id' => $record->department_id,
            'availability_status' => $record->availability_status,
            'start_date' => $record->start_date->toDateString(),
            'end_date' => $record->end_date->toDateString(),
            'alternate_user_id' => $record->alternate_user_id,
        ]);

        return back()->with('success', 'Office Head availability period recorded.');
    }
}
