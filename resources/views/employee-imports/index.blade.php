@extends('layouts.app')
@section('title', 'Employee workbook imports')
@section('content')
    <div class="page-heading">
        <div>
            <span class="eyebrow">EMPLOYEE RECORDS</span>
            <h1>Employee workbook imports</h1>
            <p>Stage confidential workbooks, review every source column, and require HRMO mapping approval before creating records.</p>
        </div>
    </div>

    <div class="content-grid">
        <section class="panel">
            <div class="panel-heading">
                <div><h2>Stage a workbook</h2><p>Only .xlsx files up to 20 MB are accepted.</p></div>
            </div>
            <div class="info-note">Staging preserves the original workbook and every source cell in private storage. No employee accounts are created and no employee records are changed during preview.</div>
            <form method="POST" action="{{ route('employee-imports.store') }}" enctype="multipart/form-data" class="form-stack top-space">
                @csrf
                <label>Excel workbook *<input type="file" name="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required></label>
                @error('file')<p class="form-error">{{ $message }}</p>@enderror
                <div class="form-actions"><button class="button button-primary">Upload for secure review</button></div>
            </form>
        </section>

        <section class="panel">
            <div class="panel-heading"><div><h2>Import history</h2><p>Private, auditable staging and final-import records.</p></div></div>
            @forelse($imports as $import)
                <a class="application-row" href="{{ route('employee-imports.show', $import) }}">
                    <span class="row-date">#{{ $import->id }}</span>
                    <span class="row-main"><b>{{ $import->original_name }}</b><small>{{ $import->row_count }} rows · {{ $import->imported_count }} imported · {{ $import->skipped_count }} skipped · {{ $import->review_count }} need review</small></span>
                    <span class="status-tag">{{ str_replace('_', ' ', $import->status) }}</span>
                </a>
            @empty
                <p class="empty-note">No employee workbooks have been staged.</p>
            @endforelse
            <div class="pagination-wrap">{{ $imports->links() }}</div>
        </section>
    </div>
@endsection
