@extends('layouts.app')
@section('title', 'Leave requirements')
@section('content')
    <div class="page-heading"><div><span class="eyebrow">SYSTEM SETTINGS</span><h1>Leave rules & requirements</h1><p>Manage approved eligibility, accrual, entitlement, filing, attachment, and application-field rules.</p></div></div>
    <div class="alert alert-info"><b>Policy caution</b><p>The form reference is Civil Service Form No. 6, Revised 2020. Confirm current CSC rules and municipal approvals before changing entitlements, deadlines, or required documents.</p></div>
    <div class="settings-list">@foreach($leaveTypes as $type)
        <section class="panel settings-card"><div class="settings-card-heading"><div><span class="status-tag {{ $type->is_active ? 'status-approved' : 'status-disapproved' }}">{{ $type->is_active ? 'Active' : 'Inactive' }}</span><h2>{{ $type->name }}</h2><small>{{ $type->code }}</small></div></div>
            <form method="POST" action="{{ route('settings.leave-types.update', $type) }}" class="settings-form">@csrf @method('PUT')
                <div class="form-grid"><label>Employee guidance<textarea name="guidance" rows="3">{{ $type->guidance }}</textarea></label><label>Required document checklist <small>Enter one checklist item per line</small><textarea name="required_documents_text" rows="4">{{ implode("\n", $type->required_documents ?? []) }}</textarea></label></div>
                <label>Policy rules (JSON)<textarea name="rules_json" rows="10" spellcheck="false" required>{{ json_encode($type->rules ?? new stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</textarea><small>Configured reference defaults must be confirmed against current CSC rules and approved municipal policy. Policy changes are audited.</small></label>
                <div class="setting-flags"><label class="checkbox-label"><input type="hidden" name="requires_credit" value="0"><input type="checkbox" name="requires_credit" value="1" @checked($type->requires_credit)> Requires leave credit</label><label class="checkbox-label"><input type="hidden" name="is_sensitive" value="0"><input type="checkbox" name="is_sensitive" value="1" @checked($type->is_sensitive)> Sensitive document access</label><label class="checkbox-label"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($type->is_active)> Available to employees</label></div>
                <div class="form-actions"><button class="button button-primary" type="submit">Save requirements</button></div>
            </form>
        </section>
    @endforeach</div>
@endsection
