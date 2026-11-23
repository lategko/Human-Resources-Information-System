@extends('layouts.app')
@section('title', $employee->exists ? 'Edit employee' : 'Add employee')
@section('content')
    <div class="page-heading"><div><span class="eyebrow">EMPLOYEE RECORD</span><h1>{{ $employee->exists ? 'Update employee' : 'Add employee' }}</h1><p>Maintain accurate personnel details and assignment information.</p></div><a class="button button-outline" href="{{ $employee->exists ? route('employees.show', $employee) : route('employees.index') }}">Cancel</a></div>
    <form method="POST" action="{{ $employee->exists ? route('employees.update', $employee) : route('employees.store') }}" class="panel form-panel"
        data-confirm-type="form"
        data-confirm-title="{{ $employee->exists ? 'Save Employee Changes?' : 'Create Employee Record?' }}"
        data-confirm-message="{{ $employee->exists ? 'Save the personnel and assignment information entered for this employee?' : 'Create an employee record using the verified information entered here?' }}"
        data-confirm-text="{{ $employee->exists ? 'Save Changes' : 'Create Employee' }}">
        @csrf @if($employee->exists)@method('PUT')@endif
        <h2>Employment details</h2><p class="form-intro">Required fields are marked with an asterisk.</p>
        <div class="form-grid">
            <label>Employee ID *<input name="employee_no" value="{{ old('employee_no', $employee->employee_no) }}" required></label>
            <label>Department *<select name="department_id" required><option value="">Select department</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected(old('department_id', $employee->department_id) == $department->id)>{{ $department->name }}{{ $department->is_active ? '' : ' (Inactive - current assignment)' }}</option>@endforeach</select></label>
            <label>Position<select name="position_id"><option value="">Select position</option>@foreach($positions as $position)<option value="{{ $position->id }}" @selected(old('position_id', $employee->position_id) == $position->id)>{{ $position->title }}{{ $position->is_active ? '' : ' (Inactive - current assignment)' }}</option>@endforeach</select></label>
            <label>Employment status *<select name="employment_status" required>@foreach(['Active','On Leave','Separated','Retired'] as $status)<option @selected(old('employment_status', $employee->employment_status ?: 'Active') === $status)>{{ $status }}</option>@endforeach</select></label>
            <label>Employment type<input name="employment_type" value="{{ old('employment_type', $employee->employment_type) }}" placeholder="Permanent, Casual, etc."></label>
            <label>Date hired<input type="date" name="date_hired" value="{{ old('date_hired', $employee->date_hired?->format('Y-m-d')) }}"></label>
        </div>
        @if($employee->exists)
            <h2 class="form-section-title">Assignment history</h2>
            <p class="privacy-note">When changing department or position, record the effective date and reason. Existing assignments are retained in the employee history.</p>
            <div class="form-grid">
                <label>Assignment effective date<input type="date" name="assignment_effective_date" max="{{ today()->toDateString() }}" value="{{ old('assignment_effective_date', today()->toDateString()) }}"></label>
                <label>Reason for assignment change<textarea name="assignment_change_reason" rows="2" maxlength="2000">{{ old('assignment_change_reason') }}</textarea></label>
            </div>
        @endif
        <h2 class="form-section-title">Personal information</h2>
        <div class="form-grid">
            <label>First name *<input name="first_name" value="{{ old('first_name', $employee->first_name) }}" required></label>
            <label>Middle name<input name="middle_name" value="{{ old('middle_name', $employee->middle_name) }}"></label>
            <label>Last name *<input name="last_name" value="{{ old('last_name', $employee->last_name) }}" required></label>
            <label>Suffix<input name="suffix" value="{{ old('suffix', $employee->suffix) }}" placeholder="Jr., III"></label>
            <label>Sex<input name="sex" value="{{ old('sex', $employee->sex) }}"></label>
            <label>Date of birth<input type="date" name="date_of_birth" value="{{ old('date_of_birth', $employee->date_of_birth?->format('Y-m-d')) }}"></label>
            <label>Civil status<input name="civil_status" value="{{ old('civil_status', $employee->civil_status) }}"></label>
            <label>Contact number<input name="contact_number" value="{{ old('contact_number', $employee->contact_number) }}"></label>
            <label>Email address<input type="email" name="email" value="{{ old('email', $employee->email) }}"></label>
            <label class="field-span">Residential address<textarea name="address" rows="2">{{ old('address', $employee->address) }}</textarea></label>
        </div>
        <h2 class="form-section-title">Compensation and emergency contact</h2>
        <p class="privacy-note">Salary and government identification data are restricted personnel information. Enter only authorized records.</p>
        <div class="form-grid">
            <label>Salary grade<input name="salary_grade" value="{{ old('salary_grade', $employee->salary_grade) }}"></label>
            <label>Salary step<input type="number" min="1" name="salary_step" value="{{ old('salary_step', $employee->salary_step) }}"></label>
            <label>Monthly basic salary<input type="number" step=".01" min="0" name="monthly_salary" value="{{ old('monthly_salary', $employee->monthly_salary) }}"></label>
            <label>Government ID references<textarea name="government_ids" rows="2">{{ old('government_ids', $employee->government_ids) }}</textarea></label>
            <label>Emergency contact name<input name="emergency_contact_name" value="{{ old('emergency_contact_name', $employee->emergency_contact_name) }}"></label>
            <label>Emergency contact number<input name="emergency_contact_number" value="{{ old('emergency_contact_number', $employee->emergency_contact_number) }}"></label>
        </div>
        <div class="form-actions"><a class="button button-outline" href="{{ $employee->exists ? route('employees.show', $employee) : route('employees.index') }}">Cancel</a><button type="submit" class="button button-primary" data-loading-text="Saving…">Save employee</button></div>
    </form>
@endsection
