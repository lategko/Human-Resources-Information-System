@extends('layouts.app')
@section('title', $user->exists ? 'Edit user account' : 'Create user account')
@section('content')
    <div class="page-heading">
        <div><span class="eyebrow">USER ACCESS</span><h1>{{ $user->exists ? 'Manage account' : 'Create account' }}</h1><p>Enter verified account information and grant only access authorized for the user’s municipal duties.</p></div>
        <a class="button button-outline" href="{{ route('users.index') }}">Back to users</a>
    </div>
    <form x-data="{ role: @js(old('role', $user->role)), firstName: @js(old('first_name', $user->first_name)), middleName: @js(old('middle_name', $user->middle_name)), lastName: @js(old('last_name', $user->last_name)), suffix: @js(old('suffix', $user->suffix)), displayName: @js(old('name', $user->name)), syncName() { if (this.firstName && this.lastName) this.displayName = [this.firstName, this.middleName, this.lastName, this.suffix].filter(Boolean).join(' '); } }" method="POST" action="{{ $user->exists ? route('users.update', $user) : route('users.store') }}" class="panel form-panel">
        @csrf @if($user->exists)@method('PUT')@endif
        <div class="panel-heading"><div><h2>Personal and account information</h2><p>Employee name information comes from the linked employee record; other account names are stored on the user account.</p></div></div>
        <div class="form-grid">
            <label x-show="role !== 'employee'" x-cloak>First name *<input name="first_name" x-model="firstName" @input="syncName" :disabled="role === 'employee'" :required="role !== 'employee'" autocomplete="given-name"></label>
            <label x-show="role !== 'employee'" x-cloak>Middle name<input name="middle_name" x-model="middleName" @input="syncName" :disabled="role === 'employee'" autocomplete="additional-name"></label>
            <label x-show="role !== 'employee'" x-cloak>Last name *<input name="last_name" x-model="lastName" @input="syncName" :disabled="role === 'employee'" :required="role !== 'employee'" autocomplete="family-name"></label>
            <label x-show="role !== 'employee'" x-cloak>Suffix<input name="suffix" x-model="suffix" @input="syncName" :disabled="role === 'employee'" autocomplete="honorific-suffix"></label>
            <label class="field-span" x-show="role !== 'employee'" x-cloak>Full name *<input name="name" x-model="displayName" :readonly="firstName && lastName" :disabled="role === 'employee'" :required="role !== 'employee'" autocomplete="name"><small>The name is composed from the name fields above.</small></label>
            <label class="field-span">Email address *<input type="email" name="email" value="{{ old('email', $user->email) }}" required autocomplete="email"></label>
            <label>Assigned role *
                <select name="role" x-model="role" required>
                    @foreach($allowedRoles as $value)
                        @php($label = ['admin' => 'ADMIN · HR OFFICE', 'office_head' => 'Office Head', 'mayor' => 'Mayor · Final Approver', 'employee' => 'Employee / Staff'][$value])
                        <option value="{{ $value }}" @selected(old('role', $user->role) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <small>Admin manages all accounts. Only assign the Mayor role or the separate Mayor-leave authority when formally authorized.</small>
            </label>
            <label x-show="role === 'office_head'" x-cloak>Assigned office / department *
                <select name="department_id" :disabled="role !== 'office_head'" :required="role === 'office_head'">
                    <option value="">Select department</option>
                    @foreach($departments as $department)
                        <option value="{{ $department->id }}" @selected((string) old('department_id', $assignedDepartmentId ?? '') === (string) $department->id)>{{ $department->name }}</option>
                    @endforeach
                </select>
                <small>Each department can have one assigned Office Head.</small>
            </label>
            <label x-show="role === 'employee'" x-cloak>Linked employee record *
                <select name="employee_id" :disabled="role !== 'employee'" :required="role === 'employee'">
                    <option value="">Select an existing employee</option>
                    @foreach($employees as $employee)
                        <option value="{{ $employee->id }}" @selected(old('employee_id', $user->employee?->id) == $employee->id)>{{ $employee->employee_no }} · {{ $employee->full_name }} — {{ $employee->department?->name }}</option>
                    @endforeach
                </select>
                <small>Employee accounts must use an existing, non-archived employee record. No employee records are created here.</small>
            </label>
            <label x-show="role !== 'employee'" x-cloak>Personal employee record (optional)
                <select name="employee_id">
                    <option value="">No employee record linked</option>
                    @foreach($employees as $employee)
                        <option value="{{ $employee->id }}" @selected(old('employee_id', $user->employee?->id) == $employee->id)>{{ $employee->employee_no }} · {{ $employee->full_name }} — {{ $employee->department?->name }}</option>
                    @endforeach
                </select>
                <small>Link this account to an existing employee record to enable personal leave and employee-record access.</small>
            </label>
            @if(!$user->exists)
                <label>Initial password *<input type="password" name="password" minlength="12" required autocomplete="new-password"><small>At least 12 characters.</small></label>
                <label>Confirm initial password *<input type="password" name="password_confirmation" minlength="12" required autocomplete="new-password"></label>
            @else
                <label>Reset password <small>Leave blank to keep the current password.</small><input type="password" name="password" minlength="12" autocomplete="new-password"></label>
                <label>Confirm new password<input type="password" name="password_confirmation" minlength="12" autocomplete="new-password"></label>
            @endif
        </div>

        <input type="hidden" name="is_active" value="0">
        <label class="checkbox-label top-space"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $user->is_active))> Account is active and may sign in</label>
        <input type="hidden" name="can_final_approve" value="0">
        <label class="checkbox-label top-space" x-show="role === 'admin'" x-cloak><input type="checkbox" name="can_final_approve" value="1" @checked(old('can_final_approve', $user->can_final_approve))> Explicitly designate as the authorized approver for a Mayor’s personal leave only</label>
        <div class="form-actions"><a class="button button-outline" href="{{ route('users.index') }}">Cancel</a><button type="submit" class="button button-primary">Save account</button></div>
    </form>
@endsection
