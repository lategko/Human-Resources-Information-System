@extends('layouts.app')
@section('title', 'System settings')
@section('content')
    <div class="page-heading"><div><span class="eyebrow">SYSTEM SETTINGS</span><h1>System settings</h1><p>Configure municipal work schedules and final approver access.</p></div></div>
    <section class="panel settings-card"><div class="panel-heading"><div><h2>Standard workweek</h2><p>Working-day counts use these selected weekdays and active holidays.</p></div></div>
        <form method="POST" action="{{ route('settings.system.update') }}" class="form-stack">@csrf @method('PUT')
            <div class="weekday-grid">@foreach([1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday',7=>'Sunday'] as $day=>$name)<label class="choice-card"><input type="checkbox" name="workweek_days[]" value="{{ $day }}" @checked(in_array($day, $workweekDays, true))><span><b>{{ $name }}</b></span></label>@endforeach</div>
            <p class="privacy-note">Configure only the approved municipal work schedule. This setting affects leave working-day calculations, not attendance violations or payroll.</p>
            <div class="form-actions"><button class="button button-primary">Save workweek</button></div>
        </form>
    </section>
    <section class="panel panel-spaced"><div class="panel-heading"><div><h2>Approval chain</h2><p>The workflow is configured as Employee → assigned department Office Head → HRMO verification → designated Administrator final approver.</p></div></div>
        <div class="workflow-steps"><div><span>1</span><b>Office Head</b><small>Assigned per department</small></div><i>→</i><div><span>2</span><b>HRMO</b><small>Verifies days and leave credits</small></div><i>→</i><div><span>3</span><b>Final approver</b><small>Administrator accounts marked authorized</small></div></div>
        <p class="privacy-note">Designate final approvers on the <a class="table-link" href="{{ route('users.index') }}">User accounts</a> page. This prototype does not infer legal authority from a job title; configure access according to municipal delegation.</p>
    </section>
@endsection
