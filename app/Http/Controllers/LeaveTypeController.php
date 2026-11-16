<?php

namespace App\Http\Controllers;

use App\Models\LeaveType;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LeaveTypeController extends Controller
{
    public function index(): View
    {
        return view('settings.leave-types', ['leaveTypes' => LeaveType::orderBy('sort_order')->get()]);
    }

    public function update(Request $request, LeaveType $leaveType, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'guidance' => ['nullable', 'string', 'max:3000'],
            'required_documents_text' => ['nullable', 'string', 'max:3000'],
            'rules_json' => ['required', 'json'],
            'requires_credit' => ['required', 'boolean'],
            'is_sensitive' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ]);

        $policyObject = json_decode($data['rules_json']);
        if (! $policyObject instanceof \stdClass) {
            throw ValidationException::withMessages(['rules_json' => 'Policy rules must be a JSON object.']);
        }
        $rules = json_decode($data['rules_json'], true, 512, JSON_THROW_ON_ERROR);
        foreach (['monthly_accrual' => 31, 'entitlement_days' => 999999, 'annual_entitlement_days' => 999999] as $key => $max) {
            if (array_key_exists($key, $rules)
                && (! is_numeric($rules[$key]) || (float) $rules[$key] < 0 || (float) $rules[$key] > $max)) {
                throw ValidationException::withMessages([
                    'rules_json' => "The {$key} policy value must be between 0 and {$max}.",
                ]);
            }
        }
        foreach (['effective_from', 'effective_through'] as $key) {
            if (isset($rules[$key])) {
                $date = is_string($rules[$key]) ? date_parse($rules[$key]) : false;
                if ($date === false || $date['error_count'] > 0 || $date['warning_count'] > 0
                    || ! checkdate((int) $date['month'], (int) $date['day'], (int) $date['year'])) {
                    throw ValidationException::withMessages(['rules_json' => "The {$key} policy value must be a valid date."]);
                }
            }
        }
        if (isset($rules['accrual_eligibility']) && ! is_array($rules['accrual_eligibility'])) {
            throw ValidationException::withMessages(['rules_json' => 'Accrual eligibility settings must be a JSON object.']);
        }
        if (isset($rules['application_fields']) && ! is_array($rules['application_fields'])) {
            throw ValidationException::withMessages(['rules_json' => 'Application fields must be a JSON array.']);
        }
        foreach ($rules['application_fields'] ?? [] as $field) {
            if (! is_array($field)
                || ! is_string($field['key'] ?? null)
                || ! preg_match('/^[a-z][a-z0-9_]{0,59}$/', $field['key'])
                || ! is_string($field['label'] ?? null)
                || ! in_array($field['type'] ?? null, ['text', 'date', 'number', 'textarea', 'select'], true)
                || ! is_bool($field['required'] ?? false)) {
                throw ValidationException::withMessages(['rules_json' => 'Each application field requires a valid key, label, type, and required flag.']);
            }
            if (($field['type'] ?? null) === 'select'
                && (! is_array($field['options'] ?? null) || $field['options'] === [])) {
                throw ValidationException::withMessages(['rules_json' => 'Each select application field must define at least one option.']);
            }
        }
        if (isset($rules['conditional_required_documents']) && ! is_array($rules['conditional_required_documents'])) {
            throw ValidationException::withMessages(['rules_json' => 'Conditional document requirements must be a JSON array.']);
        }
        if (array_key_exists('consumes_regular_credit', $rules)
            && filter_var($rules['consumes_regular_credit'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== (bool) $data['requires_credit']) {
            throw ValidationException::withMessages([
                'rules_json' => 'The consumes_regular_credit rule must match the Requires leave credit setting.',
            ]);
        }
        $rules['consumes_regular_credit'] = (bool) $data['requires_credit'];

        $leaveType->update([
            'guidance' => $data['guidance'] ?? null,
            'required_documents' => collect(preg_split('/\r\n|\r|\n/', $data['required_documents_text'] ?? ''))
                ->map(fn ($line) => trim($line))->filter()->values()->all(),
            'rules' => $rules,
            'requires_credit' => $data['requires_credit'],
            'is_sensitive' => $data['is_sensitive'],
            'is_active' => $data['is_active'],
        ]);
        $auditLogger->record('leave_type.updated', $leaveType);

        return back()->with('success', 'Leave requirements and policy settings updated.');
    }
}
