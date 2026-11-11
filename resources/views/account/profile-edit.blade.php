@extends('layouts.app')
@section('title', 'Edit profile')
@section('content')
    <div class="page-heading">
        <div><span class="eyebrow">ACCOUNT</span><h1>Edit profile</h1><p>Update your permitted personal and account information. Your role and account status cannot be changed here.</p></div>
        <a class="button button-outline" href="{{ route('account.profile') }}">Cancel</a>
    </div>

    <form method="POST" action="{{ route('account.profile.update') }}" class="panel form-panel account-edit-form">
        @csrf @method('PUT')
        <div class="panel-heading"><div><h2>Personal information</h2><p>Personal details are saved to your linked employee record when one is available.</p></div></div>
        @if($user->employee)
            <div class="form-grid">
                <label>First name<input name="first_name" value="{{ old('first_name', $user->employee->first_name) }}" autocomplete="given-name" required></label>
                <label>Middle name<input name="middle_name" value="{{ old('middle_name', $user->employee->middle_name) }}" autocomplete="additional-name"></label>
                <label>Last name<input name="last_name" value="{{ old('last_name', $user->employee->last_name) }}" autocomplete="family-name" required></label>
                <label>Suffix<input name="suffix" value="{{ old('suffix', $user->employee->suffix) }}" autocomplete="honorific-suffix"></label>
                @php($genderOptions = ['Female', 'Male', 'Non-binary', 'Prefer not to say'])
                <label>Gender
                    <select name="sex">
                        <option value="">Select gender</option>
                        @if($user->employee->sex && ! in_array($user->employee->sex, $genderOptions, true))
                            <option value="{{ $user->employee->sex }}" @selected(old('sex', $user->employee->sex) === $user->employee->sex)>{{ $user->employee->sex }}</option>
                        @endif
                        @foreach($genderOptions as $gender)
                            <option value="{{ $gender }}" @selected(old('sex', $user->employee->sex) === $gender)>{{ $gender }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Birthdate<input type="date" name="date_of_birth" value="{{ old('date_of_birth', $user->employee->date_of_birth?->format('Y-m-d')) }}" max="{{ today()->subDay()->format('Y-m-d') }}"></label>
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

        <div class="panel-heading account-form-section-heading"><div><h2>Account information</h2><p>Use this email address to sign in to the HRIS.</p></div></div>
        <div class="form-grid">
            @if($user->employee)
                <label class="field-span">Full name<input value="{{ $user->employee->full_name }}" readonly aria-describedby="profile-name-help"><small id="profile-name-help">This is based on your first, middle, and last name above.</small></label>
            @endif
            <label>Email address<input type="email" name="email" value="{{ old('email', $user->email) }}" autocomplete="email" required></label>
            <label>Assigned role<input value="{{ str_replace('_', ' ', ucfirst($user->role)) }}{{ $user->canFinalApprove() ? ' · Final approver' : '' }}" readonly></label>
            <label>Account status<input value="{{ $user->is_active ? 'Active' : 'Disabled' }}" readonly></label>
        </div>
        <div class="form-actions">
            <a class="button button-outline" href="{{ route('account.profile') }}">Cancel</a>
            <button class="button button-primary" type="submit">Save changes</button>
        </div>
    </form>
@endsection
