<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveLeaveApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isEmployee() && $this->user()->employee !== null;
    }

    public function rules(): array
    {
        $submitting = $this->input('intent') === 'submit';

        return [
            'intent' => ['required', Rule::in(['draft', 'submit'])],
            'leave_type_id' => ['required', 'integer', Rule::exists('leave_types', 'id')->where('is_active', true)],
            'other_leave_type' => ['nullable', 'string', 'max:120', Rule::requiredIf(fn () => $submitting && $this->leave_type_id && \App\Models\LeaveType::find($this->leave_type_id)?->code === 'OTHER')],
            'leave_detail_category' => ['nullable', 'string', 'max:100'],
            'leave_detail' => ['nullable', 'string', 'max:2000'],
            'destination' => ['nullable', 'string', 'max:255'],
            'additional_details' => ['nullable', 'string', 'max:3000'],
            'working_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'start_date' => [$submitting ? 'required' : 'nullable', 'date'],
            'end_date' => [$submitting ? 'required' : 'nullable', 'date', 'after_or_equal:start_date'],
            'commutation_requested' => [$submitting ? 'required' : 'nullable', 'boolean'],
            'applicant_acknowledgment' => ['nullable', 'string', 'max:120'],
            'documents' => ['nullable', 'array', 'max:10'],
            'documents.*' => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
            'document_types' => ['nullable', 'array'],
            'document_types.*' => ['nullable', 'string', 'max:120'],
        ];
    }
}
