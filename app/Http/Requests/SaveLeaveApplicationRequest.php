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
        return $this->user()?->isEmployee() && $this->user()->employee !== null;
    }

    public function rules(): array
    {
        $submitting = $this->input('intent') === 'submit';
        $leaveType = LeaveType::find($this->input('leave_type_id'));
        $documentTypes = $leaveType?->required_documents ?? [];
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
