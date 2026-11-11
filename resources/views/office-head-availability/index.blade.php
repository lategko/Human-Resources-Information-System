@extends('layouts.app')
@section('title', 'Office Head availability')
@section('content')
    <div class="page-heading">
        <div><span class="eyebrow">LEAVE WORKFLOW</span><h1>Office Head availability</h1><p>Record dated unavailability and authorized temporary alternates. Leave routing checks whether an availability period overlaps the requested leave dates.</p></div>
    </div>
    <div class="content-grid">
        <section class="panel">
            <div class="panel-heading"><div><h2>Availability history</h2><p>Records are retained for auditability.</p></div></div>
            <div class="table-wrap">
                <table><thead><tr><th>DEPARTMENT / HEAD</th><th>STATUS</th><th>PERIOD</th><th>ALTERNATE</th><th>REASON</th><th>RECORDED BY</th></tr></thead><tbody>
                    @forelse($availabilityRecords as $record)
                        <tr>
                            <td><b>{{ $record->department->name }}</b><small class="table-sub">{{ $record->officeHead->name }}</small></td>
                            <td>{{ str_replace('_', ' ', ucfirst($record->availability_status)) }}</td>
                            <td>{{ $record->start_date->format('M j, Y') }} – {{ $record->end_date->format('M j, Y') }}</td>
                            <td>{{ $record->alternate?->name ?? 'Designated final approver fallback' }}</td>
                            <td>{{ $record->reason }}</td>
                            <td>{{ $record->updater->name }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="empty-note">No availability periods have been recorded.</td></tr>
                    @endforelse
                </tbody></table>
            </div>
            <div class="pagination-wrap">{{ $availabilityRecords->links() }}</div>
        </section>
        <aside class="panel">
            <div class="panel-heading"><div><h2>Record availability</h2><p>HRMO and administrators only.</p></div></div>
            <form method="POST" action="{{ route('office-head-availability.store') }}" class="form-stack">
                @csrf
                <label>Department and assigned Office Head *
                    <select name="department_id" required>
                        <option value="">Select department</option>
                        @foreach($departments as $department)
                            <option value="{{ $department->id }}" @selected(old('department_id') == $department->id)>{{ $department->name }} — {{ $department->head->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Availability status *
                    <select name="availability_status" required>
                        <option value="temporarily_unavailable">Temporarily unavailable</option>
                        <option value="on_leave">On leave</option>
                        <option value="official_business">Official business</option>
                        <option value="unable_to_approve">Temporarily unable to approve</option>
                        <option value="available">Available</option>
                    </select>
                </label>
                <label>Start date *<input type="date" name="start_date" value="{{ old('start_date') }}" required></label>
                <label>End date *<input type="date" name="end_date" value="{{ old('end_date') }}" required></label>
                <label>Reason *
                    <textarea name="reason" rows="3" maxlength="2000" required>{{ old('reason') }}</textarea>
                </label>
                <label>Temporary alternate approver
                    <select name="alternate_user_id">
                        <option value="">Use the designated final approver if necessary</option>
                        @foreach($alternates as $alternate)
                            <option value="{{ $alternate->id }}" @selected(old('alternate_user_id') == $alternate->id)>{{ $alternate->name }} — {{ $alternate->isOfficeHead() ? 'Office Head' : 'Designated final approver' }}</option>
                        @endforeach
                    </select>
                </label>
                <button class="button button-primary" type="submit">Record availability</button>
            </form>
        </aside>
    </div>
@endsection
