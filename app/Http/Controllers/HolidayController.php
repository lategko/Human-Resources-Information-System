<?php

namespace App\Http\Controllers;

use App\Models\Holiday;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class HolidayController extends Controller
{
    public function index(): View
    {
        return view('settings.holidays', ['holidays' => Holiday::orderByDesc('holiday_date')->paginate(30)]);
    }

    public function store(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'holiday_date' => ['required', 'date', 'unique:holidays,holiday_date'],
            'name' => ['required', 'string', 'max:150'],
            'holiday_type' => ['required', Rule::in(['regular', 'special_non_working'])],
        ]);
        $holiday = Holiday::create($data + ['is_active' => true]);
        $auditLogger->record('holiday.created', $holiday);

        return back()->with('success', 'Holiday added to the working-day calendar.');
    }

    public function update(Request $request, Holiday $holiday, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'holiday_date' => ['required', 'date', Rule::unique('holidays')->ignore($holiday->id)],
            'name' => ['required', 'string', 'max:150'],
            'holiday_type' => ['required', Rule::in(['regular', 'special_non_working'])],
            'is_active' => ['required', 'boolean'],
        ]);
        $holiday->update($data);
        $auditLogger->record('holiday.updated', $holiday);

        return back()->with('success', 'Holiday settings updated.');
    }
}
