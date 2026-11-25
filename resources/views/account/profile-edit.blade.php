@extends('layouts.app')
@section('title', 'Edit profile')
@section('content')
    <div class="page-heading">
        <div><span class="eyebrow">ACCOUNT</span><h1>Edit profile</h1><p>Update your permitted personal and account information. Your role and account status cannot be changed here.</p></div>
        <a class="button button-outline" href="{{ route('account.profile') }}">Cancel</a>
    </div>

    <form method="POST" action="{{ route('account.profile.update') }}" class="panel form-panel account-edit-form" data-confirm-type="form" data-confirm-title="Save Profile Changes?" data-confirm-message="Save your updated personal and contact information?" data-confirm-text="Save Changes">
        @csrf @method('PUT')
        <div class="panel-heading"><div><h2>Personal information</h2><p>Employee name, birthdate, department, position, and employee number are managed by HR.</p></div></div>
        @if($user->employee)
            <div class="form-grid">
                <div><small>Full name</small><b>{{ $user->employee->full_name }}</b></div>
                <label>Contact number<input name="contact_number" value="{{ old('contact_number', $user->employee->contact_number) }}" autocomplete="tel"></label>
                <label class="field-span">Residential address<textarea name="address" rows="3" autocomplete="street-address">{{ old('address', $user->employee->address) }}</textarea></label>
            </div>
        @else
            <div class="form-grid" x-data="{ firstName: @js(old('first_name', $user->first_name)), middleName: @js(old('middle_name', $user->middle_name)), lastName: @js(old('last_name', $user->last_name)), suffix: @js(old('suffix', $user->suffix)), fullName: @js(old('name', $user->name)), syncName() { if (this.firstName && this.lastName) this.fullName = [this.firstName, this.middleName, this.lastName, this.suffix].filter(Boolean).join(' '); } }">
                <label>First name<input name="first_name" x-model="firstName" @input="syncName" autocomplete="given-name"></label>
                <label>Middle name<input name="middle_name" x-model="middleName" @input="syncName" autocomplete="additional-name"></label>
                <label>Last name<input name="last_name" x-model="lastName" @input="syncName" autocomplete="family-name"></label>
                <label>Suffix<input name="suffix" x-model="suffix" @input="syncName" autocomplete="honorific-suffix"></label>
                <label class="field-span">Full name<input name="name" x-model="fullName" :readonly="firstName && lastName" autocomplete="name" required></label>
                @php($genderOptions = ['Female', 'Male', 'Non-binary', 'Prefer not to say'])
                <label>Gender
                    <select name="profile_gender">
                        <option value="">Select gender</option>
                        @if($user->profile_gender && ! in_array($user->profile_gender, $genderOptions, true))
                            <option value="{{ $user->profile_gender }}" @selected(old('profile_gender', $user->profile_gender) === $user->profile_gender)>{{ $user->profile_gender }}</option>
                        @endif
                        @foreach($genderOptions as $gender)
                            <option value="{{ $gender }}" @selected(old('profile_gender', $user->profile_gender) === $gender)>{{ $gender }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Birthdate<input type="date" name="profile_birthdate" value="{{ old('profile_birthdate', $user->profile_birthdate?->format('Y-m-d')) }}" max="{{ today()->subDay()->format('Y-m-d') }}"></label>
                <label>Contact number<input name="profile_contact_number" value="{{ old('profile_contact_number', $user->profile_contact_number) }}" autocomplete="tel"></label>
                <label class="field-span">Residential address<textarea name="profile_address" rows="3" autocomplete="street-address">{{ old('profile_address', $user->profile_address) }}</textarea></label>
            </div>
        @endif

        <div class="panel-heading account-form-section-heading"><div><h2>Account information</h2><p>Sign in using your username or email address. @if($user->isAdministrator())A changed Admin email is not saved until its verification code is confirmed.@else Email is optional; a new address must be verified.@endif</p></div></div>
        <div class="form-grid">
            <label>Username<input name="username" value="{{ old('username', $user->username) }}" autocomplete="username" minlength="3" maxlength="100" @required((bool) $user->employee)></label>
            <label>{{ $user->isAdministrator() ? 'Email address' : 'Email address (optional)' }}<input type="email" name="email" value="{{ old('email', $pendingEmail ?? $user->email) }}" autocomplete="email" @required($user->isAdministrator())></label>
            <label class="field-span">New password (optional)<input type="password" name="new_password" autocomplete="new-password" minlength="12"><small>Enter your current password below to confirm this change.</small></label>
            <label>Current password<input type="password" name="current_password" autocomplete="current-password"><small>Required to change sign-in details.</small></label>
            <label>Confirm new password<input type="password" name="new_password_confirmation" autocomplete="new-password" minlength="12"></label>
            <label>Assigned role<input value="{{ str_replace('_', ' ', ucfirst($user->role)) }}{{ $user->canFinalApprove() ? ' · Final approver' : '' }}" readonly></label>
            <label>Account status<input value="{{ $user->is_active ? 'Active' : 'Disabled' }}" readonly></label>
        </div>
        @error('username')<span class="field-error" role="alert">{{ $message }}</span>@enderror
        @error('email')<span class="field-error" role="alert">{{ $message }}</span>@enderror
        @error('current_password')<span class="field-error" role="alert">{{ $message }}</span>@enderror
        @error('new_password')<span class="field-error" role="alert">{{ $message }}</span>@enderror
        <div class="form-actions">
            <a class="button button-outline" href="{{ route('account.profile') }}">Cancel</a>
            <button class="button button-primary" type="submit" data-loading-text="Saving…">Save changes</button>
        </div>
    </form>

    @if(! $pendingEmail && $errors->has('code'))
        <span class="field-error top-space" role="alert">{{ $errors->first('code') }}</span>
    @endif

    @if($user->isAdministrator() && $pendingEmail)
        <section class="panel form-panel account-edit-form top-space">
            <div class="panel-heading">
                <div>
                    <h2>Verify new email address</h2>
                    <p>A code was sent to {{ $pendingEmail }}. Your current address, {{ $user->email }}, remains active until verification succeeds.</p>
                </div>
            </div>
            <form method="POST" action="{{ route('account.profile.email-change.verify') }}" class="form-stack">
                @csrf
                <label for="email-change-code">Six-digit verification code
                    <input id="email-change-code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required>
                </label>
                @error('code')<span class="field-error" role="alert">{{ $message }}</span>@enderror
                <div class="form-actions">
                    <button class="button button-primary" type="submit">Verify new email</button>
                </div>
            </form>
            <form method="POST" action="{{ route('account.profile.email-change.resend') }}">
                @csrf
                <button class="button button-outline" type="submit">Resend verification code</button>
            </form>
        </section>
    @endif
@endsection
