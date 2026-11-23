@extends('layouts.app')
@section('title', 'System settings')
@section('content')
    <div class="page-heading"><div><span class="eyebrow">SYSTEM SETTINGS</span><h1>System settings</h1><p>Configure municipal work schedules and explicit alternate leave reviewers.</p></div></div>
    <section class="panel settings-card"><div class="panel-heading"><div><h2>Standard workweek</h2><p>Working-day counts use these selected weekdays and active holidays.</p></div></div>
        <form method="POST" action="{{ route('settings.system.update') }}" class="form-stack" data-confirm-type="form" data-confirm-title="Save System Settings?" data-confirm-message="Save the approved workweek and explicitly configured alternate reviewer assignments? These settings affect leave working-day calculations and review routing." data-confirm-text="Save Settings">@csrf @method('PUT')
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
            <div class="form-actions"><button class="button button-primary" data-loading-text="Saving…">Save workweek</button></div>
        </form>
    </section>
    <section class="panel panel-spaced"><div class="panel-heading"><div><h2>Leave approval routes</h2><p>Standard applications follow Office Head endorsement → Admin / HR Office certification → Mayor final decision.</p></div></div>
        <div class="workflow-steps"><div><span>1</span><b>Office Head</b><small>Endorse or return</small></div><i>→</i><div><span>2</span><b>Admin · HR Office</b><small>Verify and certify</small></div><i>→</i><div><span>3</span><b>Mayor</b><small>Final decision</small></div></div>
        <p class="privacy-note">Admin applicants require a separately assigned Admin reviewer. Mayor applicants require the separately configured, explicitly designated authority. Missing assignments remain queued and never bypass review.</p>
    </section>
    <section class="panel panel-spaced">
        <div class="panel-heading"><div><h2>Database backup and restore</h2><p>Encrypted database backups are stored outside the public directory and restricted to Admin / HRMO.</p></div></div>
        <div class="info-note">Automatic backups run daily at 2:00 AM when Laravel's scheduler is running. Restoring first creates a safety backup, verifies the selected backup, and places the application in maintenance mode.</div>
        <form method="POST" action="{{ route('settings.system.backups.store') }}" class="form-actions top-space" data-confirm-type="form" data-confirm-title="Create Database Backup?" data-confirm-message="Create a protected encrypted backup of the current HRIS database?" data-confirm-text="Create Backup">
            @csrf
            <button class="button button-primary" type="submit" data-loading-text="Creating backup…">Create manual backup</button>
        </form>
        <div class="table-wrap top-space">
            <table>
                <thead><tr><th>CREATED</th><th>SOURCE</th><th>SIZE</th><th>STATUS</th><th>ACTIONS</th></tr></thead>
                <tbody>
                    @forelse($databaseBackups as $backup)
                        <tr>
                            <td>{{ $backup['created_at'] ? \Illuminate\Support\Carbon::parse($backup['created_at'])->format('M j, Y g:i A') : 'Unavailable' }}</td>
                            <td>{{ isset($backup['source']) ? str_replace('_', ' ', ucfirst($backup['source'])) : '—' }}</td>
                            <td>{{ isset($backup['size_bytes']) ? number_format($backup['size_bytes'] / 1048576, 2).' MB · encrypted' : '—' }}</td>
                            <td>{{ $backup['error'] ?? 'Protected' }}</td>
                            <td>
                                @if(!isset($backup['error']))
                                    <a class="button button-outline button-small" href="{{ route('settings.system.backups.download', $backup['id']) }}">Download encrypted file</a>
                                    <details class="correction-details">
                                        <summary>Verify and restore</summary>
                                        <form method="POST" action="{{ route('settings.system.backups.restore', $backup['id']) }}" class="form-stack" data-confirm-type="warning" data-confirm-title="Restore Database Backup?" data-confirm-message="This replaces the active HRIS database. A safety backup will be created before the selected backup is restored. Confirm only after entering the administrator password and typing RESTORE." data-confirm-text="Restore Database">
                                            @csrf
                                            <label>Administrator password
                                                <input type="password" name="password" autocomplete="current-password" required>
                                            </label>
                                            <label>Type RESTORE to confirm
                                                <input name="confirmation" autocomplete="off" required>
                                            </label>
                                            <p class="privacy-note">This replaces the active database. A safety backup is created first; already-running requests are paused during restoration.</p>
                                            <button class="button button-outline" type="submit" data-loading-text="Restoring…">Restore selected backup</button>
                                        </form>
                                    </details>
                                @else
                                    <span class="field-error">{{ $backup['error'] }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="empty-note">No database backups have been created.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
