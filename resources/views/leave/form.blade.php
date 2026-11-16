@extends('layouts.app')
@section('title', $application->exists ? 'Update application' : 'Application for leave')
@section('content')
    @php
        $typeOptions = $leaveTypes->map(fn ($type) => [
            'id' => $type->id, 'code' => $type->code, 'name' => $type->name,
            'guidance' => $type->guidance, 'documents' => array_values(array_unique(array_merge(
                $type->required_documents ?? [],
                collect($type->rules['conditional_required_documents'] ?? [])->pluck('document')->filter()->all()
            ))),
            'fields' => $type->rules['application_fields'] ?? [],
            'entitlement' => $type->rules['entitlement_days'] ?? $type->rules['annual_entitlement_days'] ?? null,
            'entitlementUnit' => $type->rules['entitlement_unit'] ?? 'days',
        ])->values();
        $selectedTypeId = old('leave_type_id', $application->leave_type_id);
        $policyValues = old('policy_details', $application->policy_details ?? []);
    @endphp
    <div class="page-heading"><div><span class="eyebrow">CIVIL SERVICE FORM NO. 6 · REVISED 2020</span><h1>Application for leave</h1><p>Complete the employee and leave details. HR and approvers complete Section 7.</p></div><a class="button button-outline" href="{{ $application->exists ? route('leave.show', $application) : route('leave.index') }}">Cancel</a></div>
    <form x-data="{ ...leaveApplicationForm(@js($typeOptions), @js($selectedTypeId), @js(route('leave.working-days'))), fieldValues: @js($policyValues) }" method="POST" action="{{ $application->exists ? route('leave.update', $application) : route('leave.store') }}" enctype="multipart/form-data" class="leave-form">
        @csrf @if($application->exists)@method('PUT')@endif
        <section class="panel form-panel">
            <div class="form-section-header"><span class="section-number">01</span><div><h2>Employee information</h2><p>Profile details are populated from your employee record.</p></div></div>
            <div class="form-grid">
                <label>Office / Department<input value="{{ $employee->department?->name ?? 'Department not assigned' }}" readonly></label>
                <label>Date of filing<input value="{{ old('filed_at', $application->filed_at?->format('Y-m-d') ?? today()->format('Y-m-d')) }}" readonly></label>
                <label>Last name<input value="{{ $employee->last_name }}" readonly></label>
                <label>First name<input value="{{ $employee->first_name }}" readonly></label>
                <label>Middle name<input value="{{ $employee->middle_name ?? '—' }}" readonly></label>
                <label>Position<input value="{{ $employee->position?->title ?? '—' }}" readonly></label>
                <label>Monthly salary<input value="{{ $employee->monthly_salary ? '₱'.number_format((float)$employee->monthly_salary, 2) : 'Not recorded' }}" readonly></label>
            </div>
            <div class="info-note">Please ask HRMO to correct any profile information that is no longer accurate before filing.</div>
            <div class="credit-row"><span><b>Vacation Leave</b><small>Monthly accrual 1.25 days · subject to eligibility policy</small></span><strong>{{ number_format((float) ($leaveCredits->firstWhere('leaveType.code', 'VACATION')?->balance ?? 0), 2) }} <small>days</small></strong></div>
            <div class="credit-row"><span><b>Sick Leave</b><small>Monthly accrual 1.25 days · subject to eligibility policy</small></span><strong>{{ number_format((float) ($leaveCredits->firstWhere('leaveType.code', 'SICK')?->balance ?? 0), 2) }} <small>days</small></strong></div>
        </section>
        <section class="panel form-panel">
            <div class="form-section-header"><span class="section-number">02</span><div><h2>6. Details of application</h2><p>Choose the leave type and provide the relevant details.</p></div></div>
            <div class="form-grid">
                <label class="field-span">6.A Type of leave to be availed of *
                    <select name="leave_type_id" x-model="selectedId" required @change="updateType()">
                        <option value="">Select a leave type</option>@foreach($leaveTypes as $type)<option value="{{ $type->id }}" @selected((string)$selectedTypeId === (string)$type->id)>{{ $type->name }}</option>@endforeach
                    </select>
                </label>
            </div>
            <div class="requirements-box" x-show="selectedType" x-cloak>
                <b>Before you submit</b><p x-text="selectedType?.guidance"></p>
                <p x-show="selectedType?.entitlement !== null" x-text="`Configured entitlement: ${selectedType?.entitlement} ${selectedType?.entitlementUnit}`"></p>
                <template x-if="selectedType?.documents?.length"><ul><template x-for="document in selectedType.documents" :key="document"><li x-text="document"></li></template></ul></template>
                <small>Requirements are configurable and must be confirmed against current CSC rules and approved municipal policy.</small>
            </div>
            <div class="form-grid conditional-fields" x-show="selectedType?.fields?.length" x-cloak>
                <template x-for="field in (selectedType?.fields ?? [])" :key="field.key">
                    <label :class="['textarea', 'select'].includes(field.type) ? 'field-span' : ''">
                        <span x-text="field.label"></span>
                        <template x-if="field.type === 'textarea'">
                            <textarea :name="`policy_details[${field.key}]`" rows="3" :required="field.required" x-text="fieldValues[field.key] ?? ''"></textarea>
                        </template>
                        <template x-if="field.type === 'select'">
                            <select :name="`policy_details[${field.key}]`" :required="field.required">
                                <option value="">Select an option</option>
                                <template x-for="option in (field.options ?? [])" :key="option.value">
                                    <option :value="option.value" x-text="option.label" :selected="fieldValues[field.key] === option.value"></option>
                                </template>
                            </select>
                        </template>
                        <template x-if="!['textarea', 'select'].includes(field.type)">
                            <input :type="field.type ?? 'text'" :name="`policy_details[${field.key}]`" :required="field.required" :value="fieldValues[field.key] ?? ''">
                        </template>
                    </label>
                </template>
            </div>
            <div class="form-grid conditional-fields" x-show="selectedType && ['VACATION','FORCED'].includes(selectedType.code)" x-cloak>
                <label>6.B Details of leave<select name="leave_detail_category" x-bind:disabled="!['VACATION','FORCED'].includes(selectedType?.code)"><option value="">Select location</option><option value="Within the Philippines" @selected(old('leave_detail_category', $application->leave_detail_category) === 'Within the Philippines')>Within the Philippines</option><option value="Abroad" @selected(old('leave_detail_category', $application->leave_detail_category) === 'Abroad')>Abroad</option></select></label>
                <label>Destination, if applicable<input name="destination" x-bind:disabled="!['VACATION','FORCED'].includes(selectedType?.code)" value="{{ old('destination', $application->destination) }}" placeholder="Country / destination"></label>
            </div>
            <div class="form-grid conditional-fields" x-show="selectedType?.code === 'SICK'" x-cloak>
                <label>6.B Sick leave<select name="leave_detail_category" x-bind:disabled="selectedType?.code !== 'SICK'"><option value="">Select setting</option><option value="In Hospital" @selected(old('leave_detail_category', $application->leave_detail_category) === 'In Hospital')>In Hospital</option><option value="Out Patient" @selected(old('leave_detail_category', $application->leave_detail_category) === 'Out Patient')>Out Patient</option></select></label>
                <label>Specify illness<input name="leave_detail" x-bind:disabled="selectedType?.code !== 'SICK'" value="{{ old('leave_detail', $application->leave_detail) }}"></label>
            </div>
            <div class="form-grid conditional-fields" x-show="selectedType?.code === 'WOMEN_SPECIAL'" x-cloak><label class="field-span">Specify illness or medical condition<textarea name="leave_detail" x-bind:disabled="selectedType?.code !== 'WOMEN_SPECIAL'" rows="2">{{ old('leave_detail', $application->leave_detail) }}</textarea></label></div>
            <div class="form-grid conditional-fields" x-show="selectedType?.code === 'STUDY'" x-cloak>
                <label>6.B Purpose<select name="leave_detail_category" x-bind:disabled="selectedType?.code !== 'STUDY'"><option value="">Select purpose</option><option value="Completion of Master's Degree" @selected(old('leave_detail_category', $application->leave_detail_category) === "Completion of Master's Degree")>Completion of Master's Degree</option><option value="BAR/Board Examination Review" @selected(old('leave_detail_category', $application->leave_detail_category) === 'BAR/Board Examination Review')>BAR/Board Examination Review</option><option value="Other purpose" @selected(old('leave_detail_category', $application->leave_detail_category) === 'Other purpose')>Other purpose</option></select></label>
                <label>Other purpose, if applicable<input name="leave_detail" x-bind:disabled="selectedType?.code !== 'STUDY'" value="{{ old('leave_detail', $application->leave_detail) }}"></label>
            </div>
            <div class="form-grid conditional-fields" x-show="selectedType && ['OTHER','MONETIZATION','TERMINAL'].includes(selectedType.code)" x-cloak>
                <label x-show="selectedType?.code === 'OTHER'">Specify other leave type<input name="other_leave_type" x-bind:disabled="selectedType?.code !== 'OTHER'" value="{{ old('other_leave_type', $application->other_leave_type) }}"></label>
                <label class="field-span" x-show="selectedType?.code !== 'OTHER'">6.B Additional details<textarea name="leave_detail" x-bind:disabled="selectedType?.code !== 'MONETIZATION' && selectedType?.code !== 'TERMINAL'" rows="2">{{ old('leave_detail', $application->leave_detail) }}</textarea></label>
            </div>
            <div class="form-grid"><label class="field-span">Additional details / supporting information<textarea name="additional_details" rows="3" placeholder="Add relevant information for the reviewer">{{ old('additional_details', $application->additional_details) }}</textarea></label></div>
        </section>
        <section class="panel form-panel">
            <div class="form-section-header"><span class="section-number">03</span><div><h2>6.C Number of working days</h2><p>Weekends and configured municipal holidays are excluded.</p></div></div>
            <div class="form-grid">
                <label>Start date *<input type="date" name="start_date" x-model="startDate" @change="refreshDays()" value="{{ old('start_date', $application->start_date?->format('Y-m-d')) }}" required></label>
                <label>End date *<input type="date" name="end_date" x-model="endDate" @change="refreshDays()" value="{{ old('end_date', $application->end_date?->format('Y-m-d')) }}" required></label>
                <label>Number of working days<input value="{{ $application->working_days ?? 'Calculated from dates' }}" x-bind:value="workingDays === null ? '{{ $application->working_days ?? 'Calculated from dates' }}' : workingDays" readonly></label>
                <div class="form-hint field-span" x-text="dayMessage">The server calculates weekdays and excludes configured holidays when dates are selected.</div>
            </div>
        </section>
        <section class="panel form-panel">
            <div class="form-section-header"><span class="section-number">04</span><div><h2>6.D Commutation</h2><p>Select one option before submitting.</p></div></div>
            <div class="radio-row">
                <label class="choice-card"><input type="radio" name="commutation_requested" value="0" @checked(old('commutation_requested', $application->commutation_requested) === false || old('commutation_requested', $application->commutation_requested) === 0 || old('commutation_requested', $application->commutation_requested) === '0')><span><b>Not Requested</b><small>I am not requesting commutation.</small></span></label>
                <label class="choice-card"><input type="radio" name="commutation_requested" value="1" @checked(old('commutation_requested', $application->commutation_requested) === true || old('commutation_requested', $application->commutation_requested) === 1 || old('commutation_requested', $application->commutation_requested) === '1')><span><b>Requested</b><small>I am requesting commutation, subject to approval.</small></span></label>
            </div>
            <div class="form-grid top-space"><label>Applicant acknowledgment<input name="applicant_acknowledgment" value="{{ old('applicant_acknowledgment', $application->applicant_acknowledgment) }}" placeholder="Type your full name as acknowledgment"></label></div>
        </section>
        <section class="panel form-panel">
            <div class="form-section-header"><span class="section-number">05</span><div><h2>Supporting documents</h2><p>Upload permitted document formats (PDF, JPG, PNG), up to 10 MB per file.</p></div></div>
            <div class="upload-area"><label class="upload-control"><span>＋</span><span><b>Choose supporting files</b><small>PDF or image · maximum 10 files</small></span><input type="file" name="documents[]" multiple accept=".pdf,.jpg,.jpeg,.png" @change="setDocuments($event)"></label></div>
            <div class="form-stack top-space" x-show="documentUploads.length" x-cloak>
                <template x-for="document in documentUploads" :key="document.index">
                    <label class="document-upload-row"><span x-text="document.name"></span>
                        <select :name="`document_types[${document.index}]`" required>
                            <option value="">Select document type</option>
                            <template x-for="requiredDocument in (selectedType?.documents ?? [])" :key="requiredDocument"><option :value="requiredDocument" x-text="requiredDocument"></option></template>
                            <option value="Other supporting document">Other supporting document</option>
                        </select>
                    </label>
                </template>
            </div>
            <p class="privacy-note">Medical and VAWC documents are stored privately and access is limited. Do not upload documents that are not required for this application.</p>
        </section>
        <div class="section-7-preview"><span class="section-number">07</span><div><b>7. Details of action on application</b><p>Leave credit certification, recommendation, approval, and disapproval fields are reserved for HRMO and authorized approvers.</p></div></div>
        <div class="form-actions"><a class="button button-outline" href="{{ $application->exists ? route('leave.show', $application) : route('leave.index') }}">Cancel</a>
            <button class="button button-outline" type="submit" name="intent" value="draft" formnovalidate>Save draft</button>
            <button class="button button-primary" type="submit" name="intent" value="submit">Review and submit <span>→</span></button>
        </div>
    </form>
@endsection
