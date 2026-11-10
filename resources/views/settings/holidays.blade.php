@extends('layouts.app')
@section('title', 'Holiday calendar')
@section('content')
    <div class="page-heading"><div><span class="eyebrow">SYSTEM SETTINGS</span><h1>Holiday calendar</h1><p>Active holidays are excluded from working-day calculations for leave requests.</p></div></div>
    <div class="content-grid">
        <section class="panel"><div class="panel-heading"><div><h2>Municipal holiday calendar</h2><p>Review and update configured dates each year.</p></div></div>
            <div class="table-wrap"><table><thead><tr><th>DATE</th><th>HOLIDAY</th><th>STATUS</th><th></th></tr></thead><tbody>
                @forelse($holidays as $holiday)<tr><td>{{ $holiday->holiday_date->format('M j, Y') }}</td><td>{{ $holiday->name }}</td><td><span class="status-tag {{ $holiday->is_active ? 'status-approved' : 'status-disapproved' }}">{{ $holiday->is_active ? 'Active' : 'Inactive' }}</span></td><td><details><summary class="table-link">Edit</summary><form method="POST" action="{{ route('settings.holidays.update', $holiday) }}" class="holiday-edit">@csrf @method('PUT')<label>Date<input type="date" name="holiday_date" value="{{ $holiday->holiday_date->format('Y-m-d') }}" required></label><label>Name<input name="name" value="{{ $holiday->name }}" required></label><input type="hidden" name="is_active" value="0"><label class="checkbox-label"><input type="checkbox" name="is_active" value="1" @checked($holiday->is_active)> Active</label><button class="button button-outline">Save</button></form></details></td></tr>@empty<tr><td colspan="4" class="empty-note">No holidays configured. Dates are calculated as weekdays until HR adds holiday records.</td></tr>@endforelse
            </tbody></table></div><div class="pagination-wrap">{{ $holidays->links() }}</div>
        </section>
        <aside class="panel"><div class="panel-heading"><div><h2>Add holiday</h2><p>Add an approved holiday date.</p></div></div>
            <form method="POST" action="{{ route('settings.holidays.store') }}" class="form-stack">@csrf<label>Date *<input type="date" name="holiday_date" required></label><label>Name *<input name="name" required></label><button class="button button-primary">Add holiday</button></form>
        </aside>
    </div>
@endsection
