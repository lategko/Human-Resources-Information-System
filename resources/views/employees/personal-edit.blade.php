@extends('layouts.app')
@section('title', 'Update contact information')
@section('content')
    <div class="page-heading"><div><span class="eyebrow">MY PROFILE</span><h1>Update contact information</h1><p>Only personal contact details can be changed here. Employment and compensation details are managed by HR.</p></div><a class="button button-outline" href="{{ route('employees.show', $employee) }}">Cancel</a></div>
    <form method="POST" action="{{ route('my-profile.update') }}" class="panel form-panel" data-confirm-type="form" data-confirm-title="Save Personal Information?" data-confirm-message="Save the changes to your personal employee information?" data-confirm-text="Save Changes">@csrf @method('PUT')
        <p>To change your username or login email, use <a class="text-link" href="{{ route('account.profile.edit') }}">account settings</a>.</p>
        <div class="form-grid"><label>Contact number<input name="contact_number" value="{{ old('contact_number', $employee->contact_number) }}"></label><label class="field-span">Residential address<textarea name="address" rows="3">{{ old('address', $employee->address) }}</textarea></label><label>Emergency contact name<input name="emergency_contact_name" value="{{ old('emergency_contact_name', $employee->emergency_contact_name) }}"></label><label>Emergency contact number<input name="emergency_contact_number" value="{{ old('emergency_contact_number', $employee->emergency_contact_number) }}"></label></div>
        <div class="form-actions"><a class="button button-outline" href="{{ route('employees.show', $employee) }}">Cancel</a><button class="button button-primary">Save contact details</button></div>
    </form>
@endsection
