<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SystemSettingsController extends Controller
{
    public function index(): View
    {
        return view('settings.system', [
            'workweekDays' => SystemSetting::find('workweek_days')?->value ?? [1, 2, 3, 4, 5],
        ]);
    }

    public function update(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'workweek_days' => ['required', 'array', 'min:1', 'max:7'],
            'workweek_days.*' => ['required', 'integer', 'between:1,7', 'distinct'],
        ]);
        SystemSetting::updateOrCreate(['key' => 'workweek_days'], [
            'value' => array_values(array_map('intval', $data['workweek_days'])),
            'updated_by' => $request->user()->id,
        ]);
        $auditLogger->record('settings.workweek_updated', null, ['workweek_days' => $data['workweek_days']]);

        return back()->with('success', 'Workweek settings updated. New leave applications use the updated schedule.');
    }
}
