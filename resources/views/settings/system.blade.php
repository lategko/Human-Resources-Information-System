@extends('layouts.app')
@section('title', 'System settings')
@section('content')
    <div class="page-heading"><div><span class="eyebrow">SYSTEM SETTINGS</span><h1>System settings</h1><p>Configure municipal work schedules and explicit alternate leave reviewers.</p></div></div>
    <section class="panel settings-card"><div class="panel-heading"><div><h2>Standard workweek</h2><p>Working-day counts use these selected weekdays and active holidays.</p></div></div>
        <form method="POST" action="{{ route('settings.system.update') }}" class="form-stack">@csrf @method('PUT')
            <div class="weekday-grid">@foreach([1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday',7=>'Sunday'] as $day=>$name)<label class="choice-card"><input type="checkbox" name="workweek_days[]" value="{{ $day }}" @checked(in_array($day, $workweekDays, true))><span><b>{{ $name }}</b></span></label>@endforeach</div>
            <p class="privacy-note">Configure only the approved municipal work schedule. This setting affects leave working-day calculations.</p>
            <div class="form-grid">
                <label>Alternate Admin / HR reviewer for Admin personal leave
                    <select name="alternate_hr_reviewer_id"><option value="">Not configured</option>@foreach($hrReviewers as $reviewer)<option value="{{ $reviewer->id }}" @selected((string) $alternateHrReviewerId === (string) $reviewer->id)>{{ $reviewer->name }}</option>@endforeach</select>
                    <small>Must be an active Admin account. The applicant cannot review their own application.</small>
                </label>
                <label>Authorized approver for Mayor personal leave
                    <select name="mayor_leave_approver_id"><option value="">Not configured</option>@foreach($designatedApprovers as $approver)<option value="{{ $approver->id }}" @selected((string) $mayorLeaveApproverId === (string) $approver->id)>{{ $approver->name }}</option>@endforeach</select>
                    <small>Only an active Admin explicitly designated for this limited permission is eligible. No approver is assumed.</small>
                </label>
            </div>
            <div class="form-actions"><button class="button button-primary">Save workweek</button></div>
        </form>
    </section>
    <section class="panel panel-spaced"><div class="panel-heading"><div><h2>Leave approval routes</h2><p>Standard applications follow Office Head endorsement → Admin / HR Office certification → Mayor final decision.</p></div></div>
        <div class="workflow-steps"><div><span>1</span><b>Office Head</b><small>Endorse or return</small></div><i>→</i><div><span>2</span><b>Admin · HR Office</b><small>Verify and certify</small></div><i>→</i><div><span>3</span><b>Mayor</b><small>Final decision</small></div></div>
        <p class="privacy-note">Admin applicants require a separately assigned Admin reviewer. Mayor applicants require the separately configured, explicitly designated authority. Missing assignments remain queued and never bypass review.</p>
    </section>
@endsection
