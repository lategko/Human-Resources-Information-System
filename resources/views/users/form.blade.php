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
            <label x-show="role !== 'employee' && role !== 'office_head'" x-cloak>First name *<input name="first_name" x-model="firstName" @input="syncName" :disabled="role === 'employee' || role === 'office_head'" :required="role !== 'employee' && role !== 'office_head'" autocomplete="given-name"></label>
            <label x-show="role !== 'employee' && role !== 'office_head'" x-cloak>Middle name<input name="middle_name" x-model="middleName" @input="syncName" :disabled="role === 'employee' || role === 'office_head'" autocomplete="additional-name"></label>
            <label x-show="role !== 'employee' && role !== 'office_head'" x-cloak>Last name *<input name="last_name" x-model="lastName" @input="syncName" :disabled="role === 'employee' || role === 'office_head'" :required="role !== 'employee' && role !== 'office_head'" autocomplete="family-name"></label>
            <label x-show="role !== 'employee' && role !== 'office_head'" x-cloak>Suffix<input name="suffix" x-model="suffix" @input="syncName" :disabled="role === 'employee' || role === 'office_head'" autocomplete="honorific-suffix"></label>
            <label class="field-span" x-show="role !== 'employee' && role !== 'office_head'" x-cloak>Full name *<input name="name" x-model="displayName" :readonly="firstName && lastName" :disabled="role === 'employee' || role === 'office_head'" :required="role !== 'employee' && role !== 'office_head'" autocomplete="name"><small>The name is composed from the name fields above.</small></label>
            <label>Username<input name="username" value="{{ old('username', $user->username) }}" minlength="3" maxlength="100" autocomplete="username"><small>Employee usernames are generated automatically if left blank.</small></label>
            <label>Email address (optional)<input type="email" name="email" value="{{ old('email', $user->email) }}" autocomplete="email"><small>Required only for sending an activation or password reset link.</small></label>
            <label>Assigned role *
                <select name="role" x-model="role" required>
                    @foreach($allowedRoles as $value)
                        @php($label = ['admin' => 'ADMIN · HR OFFICE', 'office_head' => 'Office Head', 'mayor' => 'Mayor · Final Approver', 'employee' => 'Employee / Staff'][$value])
                        <option value="{{ $value }}" @selected(old('role', $user->role) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <small>Office Head access requires an employee record with an HR-approved position designation. Admin manages all accounts; assign Mayor access only when formally authorized.</small>
            </label>
            <label x-show="role === 'office_head'" x-cloak>Assigned office / department *
                <select name="department_id" :disabled="role !== 'office_head'" :required="role === 'office_head'">
                    <option value="">Select department</option>
                    @foreach($departments as $department)
                        <option value="{{ $department->id }}" @selected((string) old('department_id', $assignedDepartmentId ?? '') === (string) $department->id)>{{ $department->name }}</option>
                    @endforeach
                </select>
                <small>The selected employee’s official department must match this assignment. Each department can have one Office Head.</small>
            </label>
            <div class="field-stack" x-show="role === 'employee'" x-cloak>
                <x-employee-picker
                    input-id="user-employee-record"
                    label="Linked employee record"
                    placeholder="Search unlinked employees by name or ID"
                    :selected="$selectedEmployee"
                    :filters="['unlinked_only' => true]"
                    :required="true"
                    :disabled-expression="'role !== \'employee\''"
                    :include-selected="$user->exists"
                    :target-user-id="$user->exists ? $user->id : null"
                />
                <small>Employee accounts must use an existing, non-archived employee record. No employee records are created here.</small>
            </div>
            <div class="field-stack" x-show="role === 'office_head'" x-cloak>
                <x-employee-picker
                    input-id="user-office-head-record"
                    label="Verified Office Head employee record"
                    placeholder="Search eligible employees by name or ID"
                    :selected="$selectedEmployee"
                    :filters="['unlinked_only' => true, 'office_head_only' => true]"
                    :required="true"
                    :disabled-expression="'role !== \'office_head\''"
                    :include-selected="$user->exists"
                    :target-user-id="$user->exists ? $user->id : null"
                />
                <small>Only employees in positions explicitly marked HR-approved for their department are eligible.</small>
            </div>
            <div class="field-stack" x-show="role === 'admin' || role === 'mayor'" x-cloak>
                <x-employee-picker
                    input-id="user-personal-employee-record"
                    label="Personal employee record (optional)"
                    placeholder="No employee record or search by name or ID"
                    :selected="$selectedEmployee"
                    :filters="['unlinked_only' => true]"
                    :disabled-expression="'role !== \'admin\' && role !== \'mayor\''"
                    :include-selected="$user->exists"
                    :target-user-id="$user->exists ? $user->id : null"
                />
                <small>Link this account to an existing employee record to enable personal leave and employee-record access.</small>
            </div>
            @if(!$user->exists)
                <label x-show="role !== 'employee'" x-cloak>Initial password *<input type="password" name="password" minlength="12" :required="role !== 'employee'" :disabled="role === 'employee'" autocomplete="new-password"><small>Employee accounts receive a random password and must be activated securely.</small></label>
                <label x-show="role !== 'employee'" x-cloak>Confirm initial password *<input type="password" name="password_confirmation" minlength="12" :required="role !== 'employee'" :disabled="role === 'employee'" autocomplete="new-password"></label>
                <p class="field-span alert alert-info" x-show="role === 'employee'" x-cloak>The employee account will be created with a unique username and secure activation requirement. Add an email address and send an activation link after saving.</p>
            @endif
        </div>

        <input type="hidden" name="is_active" value="0">
        <label class="checkbox-label top-space"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $user->is_active))> Account is active and may sign in</label>
        <input type="hidden" name="can_final_approve" value="0">
        <label class="checkbox-label top-space" x-show="role === 'admin'" x-cloak><input type="checkbox" name="can_final_approve" value="1" @checked(old('can_final_approve', $user->can_final_approve))> Explicitly designate as the authorized approver for a Mayor’s personal leave only</label>
        <div class="form-actions"><a class="button button-outline" href="{{ route('users.index') }}">Cancel</a><button type="submit" class="button button-primary" data-confirm-type="form" data-confirm-title="{{ $user->exists ? 'Save Account Changes?' : 'Create User Account?' }}" data-confirm-message="{{ $user->exists ? 'Save the changes to this user account and its access settings?' : 'Create this account with the selected role and access settings?' }}" data-confirm-text="{{ $user->exists ? 'Save Changes' : 'Create Account' }}" data-loading-text="Saving…">Save account</button></div>
    </form>
    @if($user->exists)
        <section class="panel form-panel top-space">
            <div class="panel-heading"><div><h2>{{ $user->isEmployee() ? 'Employee account activation' : 'Secure password reset' }}</h2><p>Send a secure, single-use password setup/reset link. No plaintext password is shown or written to the audit log.</p></div></div>
            @if($user->email)
                <p>Link will be sent to <b>{{ $user->email }}</b>{{ $user->hasVerifiedEmail() ? ' (verified)' : ' (not verified)' }}.</p>
                <form method="POST" action="{{ route('users.activation-link', $user) }}" data-confirm-type="info" data-confirm-title="Send Password Setup Link?" data-confirm-message="Send a secure password setup or reset link to the address shown above?" data-confirm-text="Send Link">@csrf<button class="button button-primary" type="submit" data-loading-text="Sending…">Send password setup/reset link</button></form>
            @else
                <p class="alert alert-info">Add an email address above and save the account before sending a setup/reset link.</p>
            @endif
            @if($user->isEmployee() && $user->is_active)
                <hr>
                <p>For an employee without email access, generate a random temporary password for secure handoff. It is shown once and must be changed after first sign-in.</p>
                <form method="POST" action="{{ route('users.temporary-credential', $user) }}"
                    data-confirm-type="warning"
                    data-confirm-title="Generate Temporary Password?"
                    data-confirm-message="The employee’s current password will stop working immediately. A new random temporary password will be shown once and must be changed after sign-in."
                    data-confirm-text="Generate Password">
                    @csrf
                    <button class="button button-secondary" type="submit" data-loading-text="Generating…">Generate one-time temporary password</button>
                </form>
            @endif
        </section>
    @endif
@endsection
