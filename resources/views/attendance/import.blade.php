@extends('layouts.app')
@section('title', 'Biometric import #'.$import->id)
@section('content')
    <div class="page-heading"><div><span class="eyebrow">BIOMETRIC CSV IMPORT · #{{ $import->id }}</span><h1>{{ $import->original_name }}</h1><p>{{ $import->created_at->format('F j, Y · g:i A') }} · {{ $import->row_count }} rows</p></div><a class="button button-outline" href="{{ route('attendance.index') }}">Back to attendance</a></div>
    <div class="stats-grid stats-grid-3"><article class="stat-card"><span class="stat-label">IMPORTED</span><strong>{{ $import->imported_count }}</strong><small>timestamp records created</small></article><article class="stat-card"><span class="stat-label">ERRORS</span><strong>{{ $import->error_count }}</strong><small>rows not imported</small></article><article class="stat-card"><span class="stat-label">STATUS</span><strong class="status-text">{{ str_replace('_',' ',$import->status) }}</strong><small>Import file is not connected to a device</small></article></div>
    <section class="panel"><div class="panel-heading"><div><h2>Import row results</h2><p>Rows with errors can be corrected in the source file and re-imported.</p></div></div>
        <div class="table-wrap"><table><thead><tr><th>ROW</th><th>BIOMETRIC ID</th><th>EMPLOYEE</th><th>DATE</th><th>TIME IN</th><th>TIME OUT</th><th>RESULT</th></tr></thead><tbody>
        @forelse($import->records as $row)<tr><td>{{ $row->row_number }}</td><td>{{ $row->raw_data['biometric_id'] ?? '—' }}</td><td>{{ $row->employee?->full_name ?? 'Unmapped' }}</td><td>{{ $row->raw_data['date'] ?? '—' }}</td><td>{{ $row->raw_data['time_in'] ?? '—' }}</td><td>{{ $row->raw_data['time_out'] ?? '—' }}</td><td>@if($row->error_message)<span class="status-tag status-disapproved">{{ $row->error_message }}</span>@else<span class="status-tag status-approved">Imported</span>@endif</td></tr>@empty<tr><td colspan="7" class="empty-note">No data rows were processed.</td></tr>@endforelse
        </tbody></table></div>
    </section>
@endsection
