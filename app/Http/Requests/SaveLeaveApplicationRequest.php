<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Illuminate\Validation\Rule;
use App\Models\LeaveType;

class SaveLeaveApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->employee !== null;
    }

    public function rules(): array
    {
        $submitting = $this->input('intent') === 'submit';
        $leaveType = LeaveType::find($this->input('leave_type_id'));
        $documentTypes = $leaveType?->required_documents ?? [];
        array_push($documentTypes, ...collect($leaveType?->rules['conditional_required_documents'] ?? [])
            ->pluck('document')->filter()->all());
        $documentTypes = array_values(array_unique($documentTypes));
        $documentTypes[] = 'Other supporting document';
        $uploadedFiles = $this->file('documents', []);
        $hasDocuments = is_array($uploadedFiles) ? $uploadedFiles !== [] : $uploadedFiles !== null;

        return [
            'intent' => ['required', Rule::in(['draft', 'submit'])],
            'leave_type_id' => ['required', 'integer', Rule::exists('leave_types', 'id')->where('is_active', true)],
            'other_leave_type' => ['nullable', 'string', 'max:120', Rule::requiredIf(fn () => $submitting && $this->leave_type_id && \App\Models\LeaveType::find($this->leave_type_id)?->code === 'OTHER')],
            'leave_detail_category' => ['nullable', 'string', 'max:100'],
            'leave_detail' => ['nullable', 'string', 'max:2000'],
            'destination' => ['nullable', 'string', 'max:255'],
            'additional_details' => ['nullable', 'string', 'max:3000'],
            'policy_details' => ['nullable', 'array', 'max:30'],
            'policy_details.*' => ['nullable', 'string', 'max:1000'],
            'working_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'start_date' => [$submitting ? 'required' : 'nullable', 'date'],
            'end_date' => [$submitting ? 'required' : 'nullable', 'date', 'after_or_equal:start_date'],
            'commutation_requested' => [$submitting ? 'required' : 'nullable', 'boolean'],
            'applicant_acknowledgment' => ['nullable', 'string', 'max:120'],
            'documents' => ['nullable', 'array', 'max:10'],
            'documents.*' => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
            'document_types' => ['nullable', 'array', 'max:10', Rule::requiredIf($hasDocuments)],
            'document_types.*' => ['required', 'string', 'max:120', Rule::in($documentTypes)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $leaveType = LeaveType::find($this->input('leave_type_id'));
            if ($this->input('intent') === 'submit' && $leaveType) {
                $policyDetails = $this->input('policy_details', []);
                foreach ($leaveType->rules['application_fields'] ?? [] as $field) {
                    $value = is_array($policyDetails) ? ($policyDetails[$field['key']] ?? null) : null;
                    if (($value === null || (is_string($value) && trim($value) === ''))
                        && ($field['required'] ?? false)) {
                        $validator->errors()->add(
                            'policy_details.'.$field['key'],
                            'The '.$field['label'].' field is required.'
                        );
                        continue;
                    }
                    if (! is_string($value) || trim($value) === '') {
                        continue;
                    }
                    if (($field['type'] ?? null) === 'select'
                        && ! in_array($value, array_column($field['options'] ?? [], 'value'), true)) {
                        $validator->errors()->add('policy_details.'.$field['key'], 'Select a valid '.$field['label'].'.');
                    } elseif (($field['type'] ?? null) === 'number' && (! is_numeric($value) || (float) $value <= 0)) {
                        $validator->errors()->add('policy_details.'.$field['key'], 'Enter a number greater than zero.');
                    } elseif (($field['type'] ?? null) === 'date') {
                        $date = date_parse($value);
                        if ($date['error_count'] > 0 || $date['warning_count'] > 0
                            || ! checkdate((int) $date['month'], (int) $date['day'], (int) $date['year'])) {
                            $validator->errors()->add('policy_details.'.$field['key'], 'Enter a valid date.');
                        }
                    }
                }
            }

            $files = $this->file('documents', []);
            $types = $this->input('document_types', []);
            $fileCount = is_array($files) ? count($files) : ($files === null ? 0 : 1);
            $typeCount = is_array($types) ? count($types) : ($types === null ? 0 : 1);
            if ($fileCount !== $typeCount) {
                $validator->errors()->add('document_types', 'Select a document type for every uploaded file.');
            }
        });
    }
}
