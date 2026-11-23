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
                <table><thead><tr><th>DEPARTMENT / HEAD</th><th>STATUS</th><th>PERIOD</th><th>ALTERNATE</th><th>REASON</th><th>RECORDED BY</th><th>HISTORY</th>@if(auth()->user()->isAdministrator())<th>ACTIONS</th>@endif</tr></thead><tbody>
                    @forelse($availabilityRecords as $record)
                        <tr>
                            <td><b>{{ $record->department->name }}</b><small class="table-sub">{{ $record->officeHead->name }}</small></td>
                            <td>
                                {{ $record->cancelled_at ? 'Cancelled' : str_replace('_', ' ', ucfirst($record->availability_status)) }}
                                @if($record->cancelled_at)<small class="table-sub">{{ $record->cancelled_at->format('M j, Y g:i A') }} by {{ $record->cancelledBy?->name ?? 'Former user' }}</small>@endif
                            </td>
                            <td>{{ $record->start_date->format('M j, Y') }} – {{ $record->end_date->format('M j, Y') }}</td>
                            <td>{{ $record->alternate?->name ?? 'Mayor fallback when uniquely configured' }}</td>
                            <td>{{ $record->reason }}@if($record->cancellation_reason)<small class="table-sub">Cancellation: {{ $record->cancellation_reason }}</small>@endif</td>
                            <td>{{ $record->updater->name }}</td>
                            <td>
                                <details class="correction-details">
                                    <summary>{{ $record->history->count() }} change(s)</summary>
                                    <div class="form-stack">
                                        @foreach($record->history as $event)
                                            <div>
                                                <b>{{ ucfirst($event->action) }}</b>
                                                <small class="table-sub">{{ $event->actor?->name ?? 'Former user' }} · {{ $event->created_at->format('M j, Y g:i A') }}</small>
                                                @if($event->note)<small class="table-sub">{{ $event->note }}</small>@endif
                                                @if($event->before_state)
                                                    <small class="table-sub">Before: {{ str_replace('_', ' ', ucfirst($event->before_state['availability_status'] ?? '')) }} · {{ $event->before_state['start_date'] ?? '—' }} to {{ $event->before_state['end_date'] ?? '—' }} · {{ $event->before_state['reason'] ?? '' }}</small>
                                                @endif
                                                @if($event->after_state)
                                                    <small class="table-sub">After: {{ str_replace('_', ' ', ucfirst($event->after_state['availability_status'] ?? '')) }} · {{ $event->after_state['start_date'] ?? '—' }} to {{ $event->after_state['end_date'] ?? '—' }} · {{ $event->after_state['reason'] ?? '' }}</small>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </details>
                            </td>
                            @if(auth()->user()->isAdministrator())
                                <td>
                                    @if(!$record->cancelled_at)
                                        <details class="correction-details">
                                            <summary>Edit</summary>
                                            <form method="POST" action="{{ route('office-head-availability.update', $record) }}" class="form-stack" data-confirm-type="form" data-confirm-title="Save Office Head Availability?" data-confirm-message="Save the availability period and any alternate reviewer assignment?" data-confirm-text="Save Changes">
                                                @csrf
                                                @method('PUT')
                                                <label>Availability status *
                                                    <select name="availability_status" required>
                                                        @foreach(['temporarily_unavailable','on_leave','official_business','unable_to_approve','available'] as $status)
                                                            <option value="{{ $status }}" @selected($record->availability_status === $status)>{{ str_replace('_', ' ', ucfirst($status)) }}</option>
                                                        @endforeach
                                                    </select>
                                                </label>
                                                <label>Start date *<input type="date" name="start_date" value="{{ $record->start_date->toDateString() }}" required></label>
                                                <label>End date *<input type="date" name="end_date" value="{{ $record->end_date->toDateString() }}" required></label>
                                                <label>Reason *<textarea name="reason" rows="2" maxlength="2000" required>{{ $record->reason }}</textarea></label>
                                                <label>Temporary alternate approver
                                                    <select name="alternate_user_id">
                                                        <option value="">Use the Mayor fallback only when there is exactly one active Mayor</option>
                                                        @foreach($alternates as $alternate)
                                                            <option value="{{ $alternate->id }}" @selected($record->alternate_user_id === $alternate->id)>{{ $alternate->name }} — {{ $alternate->isOfficeHead() ? 'Office Head' : 'Mayor' }}</option>
                                                        @endforeach
                                                    </select>
                                                </label>
                                                <p class="privacy-note">Applications already assigned to an alternate will remain assigned; they are not automatically returned to the original Office Head.</p>
                                                <button class="button button-outline" type="submit" data-loading-text="Saving…">Save changes</button>
                                            </form>
                                        </details>
                                        <details class="correction-details">
                                            <summary>Cancel period</summary>
                                            <form method="POST" action="{{ route('office-head-availability.cancel', $record) }}" class="form-stack" data-confirm-type="warning" data-confirm-title="Cancel Availability Period?" data-confirm-message="Cancel this availability period? The cancellation will remain in the audit history, and existing alternate reviewer assignments will not be reversed automatically." data-confirm-text="Cancel Period">
                                                @csrf
                                                <label>Cancellation reason *
                                                    <textarea name="cancellation_reason" rows="2" maxlength="2000" required>{{ old('cancellation_reason') }}</textarea>
                                                </label>
                                                <p class="privacy-note">Cancellation is retained in history. Existing alternate reviewer assignments will not be reversed automatically.</p>
                                                <button class="button button-outline" type="submit" data-loading-text="Cancelling…">Cancel availability</button>
                                            </form>
                                        </details>
                                    @else
                                        <span class="empty-note">Cancelled records are read-only.</span>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ auth()->user()->isAdministrator() ? 8 : 7 }}" class="empty-note">No availability periods have been recorded.</td></tr>
                    @endforelse
                </tbody></table>
            </div>
            <div class="pagination-wrap">{{ $availabilityRecords->links() }}</div>
        </section>
        <aside class="panel">
            <div class="panel-heading"><div><h2>Record availability</h2><p>{{ auth()->user()->isOfficeHead() ? 'You may record availability for your assigned department.' : 'Admin may record availability for any assigned Office Head.' }}</p></div></div>
            <form method="POST" action="{{ route('office-head-availability.store') }}" class="form-stack" data-confirm-type="form" data-confirm-title="Record Office Head Availability?" data-confirm-message="Record this Office Head availability period and any temporary alternate reviewer?" data-confirm-text="Record Availability">
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
                        <option value="">Use the Mayor fallback only when there is exactly one active Mayor</option>
                        @foreach($alternates as $alternate)
                            <option value="{{ $alternate->id }}" @selected(old('alternate_user_id') == $alternate->id)>{{ $alternate->name }} — {{ $alternate->isOfficeHead() ? 'Office Head' : 'Mayor' }}</option>
                        @endforeach
                    </select>
                </label>
                <button class="button button-primary" type="submit" data-loading-text="Recording…">Record availability</button>
            </form>
        </aside>
    </div>
@endsection
