@extends('layouts.app')
@section('title', 'Workbook review')
@section('content')
    @php
        $mapping = $import->field_mappings ?? [];
        $dateSourceColumn = $mapping['date_source_column'] ?? null;
        $dateTarget = $mapping['date_target'] ?? 'source_only';
        $officeLabels = collect($officeColumns)->keyBy('index');
        $canConfirm = in_array($import->status, ['mapping_approved', 'review_required'], true)
            && $import->field_mappings
            && $import->previewed_at;
    @endphp
    <div class="page-heading">
        <div>
            <span class="eyebrow">CONFIDENTIAL HRMO REVIEW · IMPORT #{{ $import->id }}</span>
            <h1>{{ $import->original_name }}</h1>
            <p>{{ $import->row_count }} source rows · Uploaded by {{ $import->uploader->name }} · {{ $import->created_at->format('M j, Y H:i') }}</p>
        </div>
        <a class="button button-outline" href="{{ route('employee-imports.source', $import) }}">Download original workbook</a>
    </div>

    <div class="info-note">Original workbook SHA-256: <code>{{ $import->source_sha256 }}</code>. Source cells, formulas, number formats, duplicate headers, blank headers, and the original file are retained in private storage.</div>
    <div class="attendance-summary top-space">
        <div><small>ROWS</small><b>{{ $import->row_count }}</b></div>
        <div><small>IMPORTED</small><b>{{ $import->imported_count }}</b></div>
        <div><small>SKIPPED / DUPLICATE</small><b>{{ $import->skipped_count }}</b></div>
        <div><small>NEEDS REVIEW</small><b>{{ $import->review_count }}</b></div>
    </div>
    @if($import->previewed_at)
        <div class="info-note top-space">Validation preview completed {{ $import->previewed_at->format('M j, Y H:i') }} by {{ $import->previewer?->name ?? 'an authorized HR user' }}. {{ $import->review_count }} row(s) require review; they will remain staged and will not be imported until corrected. No employee records have been changed by preview.</div>
    @endif

    @if(in_array($import->status, ['pending_review', 'mapping_approved', 'review_required'], true))
        <section class="panel top-space">
            <div class="panel-heading"><div><h2>Step 2: Review and approve field mappings</h2><p>Choose mappings explicitly. No source column is silently inferred as an office or employee field.</p></div></div>

            <form method="GET" action="{{ route('employee-imports.show', $import) }}" class="filter-bar">
                <label>Review OFFICE source column
                    <select name="office_column" onchange="this.form.submit()">
                        <option value="" @selected($selectedOfficeColumn === 0)>Choose the source column only after HRMO review</option>
                        @foreach($officeColumns as $column)
                            <option value="{{ $column['index'] }}" @selected($selectedOfficeColumn === $column['index'])>
                                Column {{ $column['letter'] }} — duplicate OFFICE header
                            </option>
                        @endforeach
                    </select>
                </label>
                <noscript><button class="button button-outline">Load selected column values</button></noscript>
            </form>

            <div class="content-grid">
                <section class="info-note">
                    <strong>Ambiguous source fields</strong>
                    <p>Both OFFICE columns and every unnamed column remain separate source fields. Only the OFFICE column explicitly selected below is used for department mapping. Unselected OFFICE columns and unnamed columns remain source-only unless an HRMO-approved mapping is explicitly chosen.</p>
                    <ul>
                        @foreach($ambiguousColumns as $column)
                            @php
                                $fieldKey = (string) $column['index'];
                            @endphp
                            <li>
                                <b>Column {{ $column['letter'] }} — {{ $column['display_label'] }}</b>
                                @if(mb_strtoupper((string) $column['label']) === 'OFFICE')
                                    {{ $selectedOfficeColumn === $column['index'] ? '(selected for department mapping)' : '(retained separately; not mapped)' }}
                                @else
                                    (retained source-only by default)
                                @endif
                                @if($column['label'] === null)
                                    @php
                                        $cellCount = $import->rows->filter(fn ($row) => collect($row->source_data)->contains(fn ($cell) => $cell['column'] === $column['letter'] && trim((string) $cell['display_value']) !== ''))->count();
                                    @endphp
                                    <small>Non-empty in {{ $cellCount }} row(s). It is not assigned to an employee field unless HRMO selects and approves a target below.</small>
                                @endif
                                <label class="top-space">HRMO meaning / review note
                                    <input type="text" name="ambiguous_field_notes[{{ $column['index'] }}]" form="employee-import-mapping" maxlength="500" value="{{ old('ambiguous_field_notes.'.$column['index'], $mapping['ambiguous_field_notes'][$fieldKey] ?? '') }}" placeholder="Optional; do not guess">
                                </label>
                            </li>
                        @endforeach
                    </ul>
                </section>

                <section class="panel panel-muted">
                    <p><b>Original source column meanings</b> are recorded by column letter and preserved exactly. The blank-header date is source-only unless you deliberately select an employee date field and approve it.</p>
                    <form id="employee-import-mapping" method="POST" action="{{ route('employee-imports.mapping', $import) }}" class="form-stack">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="office_source_column" value="{{ $selectedOfficeColumn }}">

                        <label>Employee name format *
                            <select name="name_format" required>
                                <option value="">Select the confirmed source format</option>
                                <option value="surname_comma_given" @selected(old('name_format', $mapping['name_format'] ?? '') === 'surname_comma_given')>Surname, Given Name Middle Name</option>
                                <option value="given_middle_surname" @selected(old('name_format', $mapping['name_format'] ?? '') === 'given_middle_surname')>Given Name Middle Name Surname</option>
                            </select>
                        </label>
                        <label>Verified employment status *
                            <select name="employment_status" required>
                                <option value="">Select after HRMO verification</option>
                                @foreach(['Active', 'On Leave', 'Separated', 'Retired'] as $status)
                                    <option value="{{ $status }}" @selected(old('employment_status', $mapping['employment_status'] ?? '') === $status)>{{ $status }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label>Unnamed date source column
                            <select name="date_source_column">
                                <option value="">Do not map an unnamed date</option>
                                @foreach($ambiguousColumns as $column)
                                    @if($column['label'] === null)
                                        <option value="{{ $column['index'] }}" @selected((string) old('date_source_column', $dateSourceColumn) === (string) $column['index'])>Column {{ $column['letter'] }} — unnamed</option>
                                    @endif
                                @endforeach
                            </select>
                        </label>
                        <label>Unnamed date mapping
                            <select name="date_target">
                                <option value="source_only" @selected(old('date_target', $dateTarget) === 'source_only')>Keep source-only (recommended until HRMO confirms meaning)</option>
                                <option value="date_of_birth" @selected(old('date_target', $dateTarget) === 'date_of_birth')>Date of birth</option>
                                <option value="date_hired" @selected(old('date_target', $dateTarget) === 'date_hired')>Date hired</option>
                                <option value="license_expiration_date" @selected(old('date_target', $dateTarget) === 'license_expiration_date')>License expiration date</option>
                                <option value="expected_retirement_date" @selected(old('date_target', $dateTarget) === 'expected_retirement_date')>Expected retirement date</option>
                            </select>
                        </label>
                        <div class="info-note">Mapping the unnamed date to an employee field is an explicit HRMO decision and will be included in the mapping audit. It remains source-only by default.</div>

                        <h3>Map each source office to an existing department</h3>
                        <p>{{ $selectedOfficeColumn ? 'Column '.$officeLabels[$selectedOfficeColumn]['letter'].' is selected for department mapping.' : 'First select which OFFICE column HRMO confirms as the department assignment.' }} No department will be created automatically.</p>
                        @foreach($officeValues as $officeValue)
                            @php
                                $officeKey = hash('sha256', $officeValue);
                            @endphp
                            <label>Source value: <b>{{ $officeValue }}</b>
                                <select name="department_map[{{ $officeKey }}]" required>
                                    <option value="">Select an existing department</option>
                                    @foreach($departments as $department)
                                        <option value="{{ $department->id }}" @selected((string) old('department_map.'.$officeKey, $mapping['department_map'][$officeKey] ?? '') === (string) $department->id)>{{ $department->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                        @endforeach

                        <h3>Map latest positions</h3>
                        @foreach($positionPairs as $pair)
                            @php
                                $positionKey = hash('sha256', $pair['office']."\0".$pair['position']);
                            @endphp
                            <label>{{ $pair['office'] }} — {{ $pair['position'] }}
                                <select name="position_map[{{ $positionKey }}]" required>
                                    <option value="">Select an existing position</option>
                                    @foreach($positions as $position)
                                        <option value="{{ $position->id }}" @selected((string) old('position_map.'.$positionKey, $mapping['position_map'][$positionKey] ?? '') === (string) $position->id)>{{ $position->title }}{{ $position->department ? ' — '.$position->department->name : '' }}</option>
                                    @endforeach
                                </select>
                            </label>
                        @endforeach
                        @error('mapping')<p class="form-error">{{ $message }}</p>@enderror
                        @foreach($errors->all() as $error)<p class="form-error">{{ $error }}</p>@endforeach
                        @php
                            $mappingPrerequisitesMissing = $selectedOfficeColumn === 0
                                || $officeValues->isEmpty()
                                || $positionPairs->isEmpty()
                                || $departments->isEmpty()
                                || $positions->isEmpty();
                        @endphp
                        <div class="form-actions"><button class="button button-primary" @disabled($mappingPrerequisitesMissing)>Save and approve mappings</button></div>
                        @if($selectedOfficeColumn === 0)
                            <p class="text-muted">Select an OFFICE source column above to load its values before saving mappings.</p>
                        @elseif($officeValues->isEmpty())
                            <p class="text-muted">The selected OFFICE column has no values to map. Choose the source column that contains the employee offices.</p>
                        @elseif($positionPairs->isEmpty())
                            <p class="text-muted">No rows contain both an office value and a latest position. Choose the correct OFFICE column or review the source workbook.</p>
                        @elseif($departments->isEmpty() || $positions->isEmpty())
                            <p class="text-muted">Add existing active departments and positions before approving mappings. This import will not create them automatically.</p>
                        @else
                            <p class="text-muted">Complete every required department and position mapping, and confirm the employee-name format and employment status.</p>
                        @endif
                    </form>
                </section>
            </div>

            <form method="POST" action="{{ route('employee-imports.confirm', $import) }}" class="form-stack top-space" onsubmit="return confirm('Create new employee records from valid rows? Existing and duplicate records will not be overwritten. Rows requiring review will remain staged.');">
                @csrf
                <h3>Step 4: Confirm import</h3>
                <label class="checkbox-label"><input type="checkbox" name="confirm_import" value="1" required @disabled(! $canConfirm)> I reviewed the validation preview and confirm importing valid rows only.</label>
                <div class="form-actions">
                    <button class="button button-danger" @disabled(! $canConfirm)>Confirm import of valid records</button>
                    @if(! $canConfirm)
                        <span class="text-muted">{{ ! $import->field_mappings ? 'Approve the field mappings first.' : (! $import->previewed_at ? 'Save the mappings to validate rows before importing.' : 'This workbook has already been processed.') }}</span>
                    @endif
                </div>
            </form>
        </section>
    @endif

    @if($import->mappingEvents->isNotEmpty())
        <section class="panel top-space">
            <div class="panel-heading"><div><h2>Mapping audit history</h2><p>Each approval records the source column numbers, destinations, and approver.</p></div></div>
            @foreach($import->mappingEvents as $event)
                <div class="application-row"><span class="row-date">{{ $event->created_at->format('M j, Y H:i') }}</span><span class="row-main"><b>{{ $event->changer->name }}</b><small>Office column {{ $event->mapping['office_source_column'] }} · {{ count($event->mapping['department_map']) }} departments · {{ count($event->mapping['position_map']) }} positions · unnamed date: {{ str_replace('_', ' ', $event->mapping['date_target']) }}</small></span></div>
            @endforeach
        </section>
    @endif

    <section class="panel top-space">
        <div class="panel-heading"><div><h2>Source workbook preview</h2><p>All source columns, including both OFFICE fields and unnamed date column, are shown without merging or dropping values.</p></div></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Excel row</th><th>Review status</th><th>Parsed name / HRMO review</th>@foreach($import->source_headers as $header)<th>{{ $header['letter'] }} · {{ $header['display_label'] }}</th>@endforeach</tr></thead>
                <tbody>
                    @foreach($rows as $row)
                        <tr>
                            <td>{{ $row->source_row }}</td>
                            @php
                                $rowStatusLabel = match ($row->status) {
                                    'duplicate' => 'Duplicate',
                                    'imported' => 'Successfully imported',
                                    'needs_review' => 'Incomplete — HRMO review required',
                                    'pending_review' => $import->previewed_at ? 'Ready for import · Valid' : 'Pending HRMO review',
                                    default => str_replace('_', ' ', $row->status),
                                };
                            @endphp
                            <td><span class="status-tag">{{ $rowStatusLabel }}</span>@if($row->issues)<small class="form-error">{{ implode(' ', $row->issues) }}</small>@endif</td>
                            @php
                                $parsedName = $parsedNames[$row->id] ?? null;
                            @endphp
                            <td>
                                @if($parsedName)
                                    {{ trim($parsedName['first_name'].' '.($parsedName['middle_name'] ?? '').' '.$parsedName['last_name']) }} {{ $parsedName['suffix'] ?? '' }}
                                @else
                                    <span class="text-muted">Name requires HRMO parsing</span>
                                @endif
                                @if(in_array($import->status, ['pending_review', 'mapping_approved', 'review_required'], true) && !in_array($row->status, ['duplicate', 'imported'], true))
                                    <details class="correction-details">
                                        <summary>{{ $row->review_data ? 'Edit reviewed name' : 'Review / correct name' }}</summary>
                                        <form method="POST" action="{{ route('employee-imports.rows.review', [$import, $row]) }}" class="correction-form">
                                            @csrf
                                            @method('PUT')
                                            <label>First name<input name="first_name" maxlength="100" required value="{{ old('first_name', $parsedName['first_name'] ?? '') }}"></label>
                                            <label>Middle name<input name="middle_name" maxlength="100" value="{{ old('middle_name', $parsedName['middle_name'] ?? '') }}"></label>
                                            <label>Last name<input name="last_name" maxlength="100" required value="{{ old('last_name', $parsedName['last_name'] ?? '') }}"></label>
                                            <label>Suffix<input name="suffix" maxlength="20" value="{{ old('suffix', $parsedName['suffix'] ?? '') }}"></label>
                                            <button class="button button-outline">Save reviewed name</button>
                                        </form>
                                        @if($row->reviewer)<small>Reviewed by {{ $row->reviewer->name }}{{ $row->reviewed_at ? ' · '.$row->reviewed_at->format('M j, Y H:i') : '' }}</small>@endif
                                    </details>
                                @elseif($row->reviewer)
                                    <small>HRMO reviewed by {{ $row->reviewer->name }}</small>
                                @endif
                            </td>
                            @foreach($row->source_data as $cell)
                                <td>{{ $cell['display_value'] }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="pagination-wrap">{{ $rows->links() }}</div>
    </section>
@endsection
