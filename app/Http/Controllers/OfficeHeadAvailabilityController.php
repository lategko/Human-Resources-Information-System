<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\OfficeHeadAvailability;
use App\Models\User;
use App\Services\OfficeHeadAvailabilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OfficeHeadAvailabilityController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $departments = Department::with('head')->whereNotNull('head_user_id')->orderBy('name');
        $records = OfficeHeadAvailability::with([
            'department', 'officeHead', 'alternate', 'updater', 'cancelledBy', 'history.actor',
        ])
            ->latest('start_date');
        if ($user->isOfficeHead()) {
            $departments->where('head_user_id', $user->id);
            $records->where('office_head_user_id', $user->id);
        }

        return view('office-head-availability.index', [
            'departments' => $departments->get(),
            'availabilityRecords' => $records->paginate(20),
            'alternates' => User::query()->where('is_active', true)
                ->where(function ($query) {
                    $query->where('role', 'office_head')
                        ->orWhere('role', 'mayor');
                })
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request, OfficeHeadAvailabilityService $availabilityService): RedirectResponse
    {
        abort_unless($request->user()->isAdministrator() || $request->user()->isOfficeHead(), 403);
        $data = $request->validate([
            'department_id' => [
                'required',
                'integer',
                Rule::exists('departments', 'id')->whereNotNull('head_user_id'),
                Rule::when($request->user()->isOfficeHead(), Rule::exists('departments', 'id')->where('head_user_id', $request->user()->id)),
            ],
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
                $alternate->is_active && ($alternate->isOfficeHead() || $alternate->isMayor()),
                422,
                'Choose an active Office Head or the Mayor as the alternate.'
            );
        }

        if ($data['availability_status'] === 'available' && $alternate) {
            abort(422, 'An alternate approver can only be assigned while the Office Head is unavailable.');
        }

        $availabilityService->record($data, $request->user());

        return back()->with('success', 'Office Head availability period recorded.');
    }

    public function update(
        Request $request,
        OfficeHeadAvailability $availability,
        OfficeHeadAvailabilityService $availabilityService
    ): RedirectResponse {
        abort_unless($request->user()->isAdministrator(), 403);
        $data = $request->validate([
            'availability_status' => ['required', Rule::in(['available', 'temporarily_unavailable', 'on_leave', 'official_business', 'unable_to_approve'])],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['required', 'string', 'max:2000'],
            'alternate_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        if ($data['availability_status'] === 'available' && ! empty($data['alternate_user_id'])) {
            abort(422, 'An alternate approver can only be assigned while the Office Head is unavailable.');
        }

        $availabilityService->update($availability, $data, $request->user());

        return back()->with('success', 'Office Head availability period updated. Existing alternate assignments were preserved.');
    }

    public function cancel(
        Request $request,
        OfficeHeadAvailability $availability,
        OfficeHeadAvailabilityService $availabilityService
    ): RedirectResponse {
        abort_unless($request->user()->isAdministrator(), 403);
        $data = $request->validate([
            'cancellation_reason' => ['required', 'string', 'max:2000'],
        ]);

        $availabilityService->cancel($availability, $data['cancellation_reason'], $request->user());

        return back()->with('success', 'Office Head availability period cancelled and retained in history.');
    }
}
