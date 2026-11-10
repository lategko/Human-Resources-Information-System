@extends('layouts.app')
@section('title', 'Audit logs')
@section('content')
    <div class="page-heading"><div><span class="eyebrow">SYSTEM SECURITY</span><h1>Audit logs</h1><p>Review recorded sign-ins and sensitive HRIS actions.</p></div></div>
    <section class="panel">
        <form class="filter-bar" method="GET"><label>Action<input name="action" value="{{ request('action') }}" placeholder="e.g. leave.approved"></label><label>Date<input type="date" name="date" value="{{ request('date') }}"></label><button class="button button-outline">Filter</button></form>
        <div class="table-wrap"><table><thead><tr><th>DATE & TIME</th><th>USER</th><th>ACTION</th><th>RECORD</th><th>DETAILS</th><th>IP ADDRESS</th></tr></thead><tbody>
        @forelse($logs as $log)<tr><td>{{ $log->created_at->format('M j, Y · g:i:s A') }}</td><td>{{ $log->user?->name ?? 'System' }}</td><td><code>{{ $log->action }}</code></td><td>{{ $log->subject_type ? class_basename($log->subject_type).' #'.$log->subject_id : '—' }}</td><td>{{ collect($log->metadata ?? [])->except(['password','government_ids'])->map(fn($value,$key)=>$key.': '.(is_scalar($value)?$value:json_encode($value)))->implode(' · ') ?: '—' }}</td><td>{{ $log->ip_address ?? '—' }}</td></tr>@empty<tr><td colspan="6" class="empty-note">No audit records match these filters.</td></tr>@endforelse
        </tbody></table></div><div class="pagination-wrap">{{ $logs->links() }}</div>
    </section>
@endsection
