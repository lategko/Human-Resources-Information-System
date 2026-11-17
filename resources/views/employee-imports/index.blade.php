@extends('layouts.app')
@section('title', 'Employee workbook imports')
@section('content')
    <div class="page-heading">
        <div>
            <span class="eyebrow">EMPLOYEE RECORDS</span>
            <h1>Employee workbook imports</h1>
            <p>Upload a workbook, confirm automatic department and position matches, then import valid employee accounts.</p>
        </div>
    </div>

    <div class="content-grid">
        <section class="panel">
            <div class="panel-heading">
                <div><h2>Step 1: Upload Excel workbook</h2><p>.xlsx and .xls files up to 20 MB are accepted.</p></div>
            </div>
            <div class="info-note">The workbook is read and analyzed automatically after upload. The original workbook and every source cell are preserved in private storage. Valid employee data is imported after Admin confirms; duplicates are skipped and problematic rows remain available for correction.</div>
            <form method="POST" action="{{ route('employee-imports.store') }}" enctype="multipart/form-data" class="form-stack top-space">
                @csrf
                <label>Excel workbook *<input type="file" name="file" accept=".xlsx,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel" required></label>
                @error('file')<p class="form-error">{{ $message }}</p>@enderror
                <div class="form-actions"><button class="button button-primary">Upload for secure review</button></div>
            </form>
        </section>

        <section class="panel">
            <div class="panel-heading">
                <div><h2>Import history</h2><p>Private, auditable batches with resumable results.</p></div>
            </div>
            @forelse($imports as $import)
                <a class="application-row" href="{{ route('employee-imports.show', $import) }}">
                    <span class="row-date">#{{ $import->id }}</span>
                    @php
                        $statusLabel = match ($import->status) {
                            'mapping_required', 'pending_review' => 'Mapping Required',
                            'ready_for_import', 'mapping_approved' => 'Ready for Import',
                            'importing' => 'Importing',
                            'completed' => 'Completed',
                            'completed_with_errors', 'review_required' => 'Completed with Errors',
                            'failed' => 'Failed',
                            default => ucfirst(str_replace('_', ' ', $import->status)),
                        };
                    @endphp
                    <span class="row-main"><b>{{ $import->original_name }}</b><small>{{ $import->worksheet_name ? $import->worksheet_name.' worksheet · ' : '' }}{{ $import->row_count }} total employee records{{ $import->excluded_rows_count ? ' · '.$import->excluded_rows_count.' non-employee rows excluded' : '' }} · {{ $import->imported_rows_count }} new · {{ $import->updated_rows_count }} updated · {{ $import->accounts_created_count }} accounts created · {{ $import->duplicate_rows_count }} duplicates skipped · {{ $import->correction_rows_count }} requiring correction · {{ $import->failed_rows_count }} failed</small></span>
                    <span class="status-tag">{{ $statusLabel }}</span>
                </a>
            @empty
                <p class="empty-note">No employee workbooks have been staged.</p>
            @endforelse
            <div class="pagination-wrap">{{ $imports->links() }}</div>
        </section>
    </div>
@endsection
