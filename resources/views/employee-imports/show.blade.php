@extends('layouts.app')
@section('title', 'Review employee import')
@section('content')
    <div class="page-heading">
        <div>
            <span class="eyebrow">EMPLOYEE RECORDS · BATCH #{{ $import->id }}</span>
            <h1>Review employee import</h1>
            <p>Review the detected {{ $import->worksheet_name }} employee sheet, correct exceptions, and import valid employee accounts. No employee-by-employee HR approval is needed.</p>
        </div>
        <a class="button button-secondary" href="{{ route('employee-imports.source', $import) }}">Download original workbook</a>
    </div>

    @if (session('success'))<div class="success-note">{{ session('success') }}</div>@endif
    @if (session('warning'))<div class="warning-note">{{ session('warning') }}</div>@endif

    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Step 2: Automatic analysis</h2>
                <p>{{ $import->original_name }} · {{ $summary['total'] }} occupied employee rows detected on {{ $import->worksheet_name ?: 'the employee' }} worksheet (headers on row {{ $import->header_row ?: 1 }})</p>
                @if($import->worksheet_names)
                    <details class="top-space"><summary>{{ count($import->worksheet_names) }} worksheets recognized</summary><p>{{ implode(' · ', $import->worksheet_names) }}</p></details>
                @endif
            </div>
            <span class="status-tag">{{ match($import->status) {
                'mapping_required', 'pending_review' => 'Mapping Required',
                'ready_for_import', 'mapping_approved' => 'Ready for Import',
                'importing' => 'Importing',
                'completed' => 'Completed',
                'completed_with_errors', 'review_required' => 'Completed with Errors',
                'failed' => 'Failed',
                default => ucfirst(str_replace('_', ' ', $import->status)),
            } }}</span>
        </div>
        <div class="summary-grid">
            <div class="summary-card"><b>{{ $summary['valid'] }}</b><span>Ready to import</span></div>
            <div class="summary-card"><b>{{ $summary['new_records'] }}</b><span>New employee records</span></div>
            <div class="summary-card"><b>{{ $summary['accounts_to_create'] }}</b><span>New accounts</span></div>
            <div class="summary-card"><b>{{ $summary['existing_to_update'] }}</b><span>Existing employees to update</span></div>
            <div class="summary-card"><b>{{ $summary['imported'] }}</b><span>Successfully imported</span></div>
            <div class="summary-card"><b>{{ $summary['updated'] }}</b><span>Employee records updated</span></div>
            <div class="summary-card"><b>{{ $summary['accounts_created'] }}</b><span>Accounts created</span></div>
            <div class="summary-card"><b>{{ $summary['office_heads'] }}</b><span>Verified Office Heads</span></div>
            <div class="summary-card"><b>{{ $summary['employees'] }}</b><span>Employee accounts</span></div>
            <div class="summary-card"><b>{{ $summary['departments'] }}</b><span>Departments detected</span></div>
            <div class="summary-card"><b>{{ $summary['matched_departments'] }}</b><span>Departments matched</span></div>
            <div class="summary-card"><b>{{ $summary['unmatched_departments']->count() }}</b><span>Departments unmatched</span></div>
            <div class="summary-card"><b>{{ $summary['matched_positions'] }}</b><span>Positions matched</span></div>
            <div class="summary-card"><b>{{ $summary['unmatched_positions']->count() }}</b><span>Positions unmatched</span></div>
            <div class="summary-card"><b>{{ $summary['duplicates'] }}</b><span>Duplicates to skip</span></div>
            <div class="summary-card"><b>{{ $summary['corrections'] }}</b><span>Records to correct</span></div>
            <div class="summary-card"><b>{{ $summary['failed'] }}</b><span>Failed records</span></div>
        </div>
        <p class="info-note top-space">Email is optional during import. Each new account receives a unique, randomly generated temporary credential and must complete secure password setup; HR can send an activation link after an email is added. Only HR-approved Office Head designations receive that role.</p>
    </section>

    @if($canEditMappings)
        <section class="panel">
            <div class="panel-heading">
                <div><h2>Step 3: Confirm automatic matches</h2><p>Choose the workbook’s OFFICE column for department / organizational unit names. Departments and positions are matched automatically; only exceptions need your attention.</p></div>
            </div>
            <form method="GET" action="{{ route('employee-imports.show', $import) }}" class="form-actions top-space">
                <label>Office / organizational unit column
                    <select name="office_column" required>
                        <option value="">Select the column containing office names</option>
                        @foreach($officeColumns as $column)
                            <option value="{{ $column['index'] }}" @selected($selectedOfficeColumn === (int) $column['index'])>
                                Column {{ $column['letter'] }} — {{ $column['label'] }} ({{ $column['filled'] }} unique values){{ $suggestedOfficeColumn === (int) $column['index'] ? ' — suggested' : '' }}
                            </option>
                        @endforeach
                    </select>
                </label>
                <button class="button button-secondary">Analyze column</button>
            </form>

            @if($selectedOfficeColumn)
                <form method="POST" action="{{ route('employee-imports.mapping', $import) }}" class="form-stack top-space">
                    @csrf
                    @method('PUT')
                    @if($errors->any())
                        <div class="warning-note"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
                    @endif
                    <input type="hidden" name="office_source_column" value="{{ $selectedOfficeColumn }}">
                    <input type="hidden" name="employment_status" value="{{ $import->field_mappings['employment_status'] ?? 'Active' }}">

                    @if($officeValues->isNotEmpty())
                        <h3>Departments</h3>
                        <p class="muted">Each distinct office is assigned automatically when it matches an active department. For an unmatched value, select an existing department or explicitly create one.</p>
                        @foreach($officeValues as $office)
                            @php
                                $key = hash('sha256', $office);
                                $selectedDepartment = old("department_map.{$key}", $mappingSummary['department_map'][$key] ?? '');
                                $matchedDepartment = $selectedDepartment !== 'create'
                                    ? $departments->firstWhere('id', (int) $selectedDepartment)
                                    : null;
                            @endphp
                            @if($matchedDepartment)
                                <input type="hidden" name="department_map[{{ $key }}]" value="{{ $matchedDepartment->id }}">
                            @else
                            <label class="mapping-row">
                                <span><b>{{ $office }}</b><small>{{ $matchedDepartment ? 'Matched: '.$matchedDepartment->name : 'Needs department assignment' }}</small></span>
                                    <select name="department_map[{{ $key }}]">
                                        <option value="">Choose a department</option>
                                        @php $departmentSuggestions = $mappingSummary['department_suggestions'][$key] ?? []; @endphp
                                        @if($departmentSuggestions)
                                            <optgroup label="Suggested matches">
                                                @foreach($departmentSuggestions as $suggestion)
                                                    <option value="{{ $suggestion['id'] }}" @selected((string) $selectedDepartment === (string) $suggestion['id'])>{{ $suggestion['name'] }} ({{ $suggestion['score'] }}% match)</option>
                                                @endforeach
                                            </optgroup>
                                        @endif
                                        <optgroup label="All existing departments">
                                            @foreach($departments as $department)
                                                <option value="{{ $department->id }}" @selected((string) $selectedDepartment === (string) $department->id)>{{ $department->name }}{{ $department->code ? ' ('.$department->code.')' : '' }}</option>
                                            @endforeach
                                        </optgroup>
                                        <option value="create" @selected($selectedDepartment === 'create')>Create department named “{{ $office }}”</option>
                                    </select>
                            </label>
                            @endif
                        @endforeach
                    @else
                        <p class="empty-note">No office values were found in this column. Select the column containing employee department names before saving.</p>
                    @endif

                    @if($positionPairs->isNotEmpty())
                        <h3 class="top-space">Positions</h3>
                        <p class="muted">Existing positions are selected automatically. Unmatched titles can be linked to an existing position or explicitly added to that department.</p>
                        @foreach($positionPairs as $pair)
                            @php
                                $key = hash('sha256', $pair['office']."\0".$pair['position']);
                                $selectedPosition = old("position_map.{$key}", $mappingSummary['position_map'][$key] ?? '');
                                $departmentKey = hash('sha256', $pair['office']);
                                $departmentId = old("department_map.{$departmentKey}", $mappingSummary['department_map'][$departmentKey] ?? null);
                                $departmentId = filter_var($departmentId, FILTER_VALIDATE_INT) ?: null;
                                $positionCandidates = $positions->filter(fn($position) => !$position->department_id || (int)$position->department_id === (int)$departmentId);
                                $matchedPosition = $selectedPosition !== 'create'
                                    ? $positionCandidates->firstWhere('id', (int) $selectedPosition)
                                    : null;
                                $positionSuggestions = $mappingSummary['position_suggestions'][$key] ?? [];
                                $suggestedPositionIds = collect($positionSuggestions)->pluck('id')->all();
                            @endphp
                            @if($matchedPosition)
                                <input type="hidden" name="position_map[{{ $key }}]" value="{{ $matchedPosition->id }}">
                            @else
                            <label class="mapping-row">
                                <span><b>{{ $pair['position'] }}</b><small>{{ $pair['office'] }} · {{ $matchedPosition ? 'Matched: '.$matchedPosition->title : 'Needs position assignment' }}</small></span>
                                    <select name="position_map[{{ $key }}]">
                                        <option value="">Choose a position</option>
                                        @if($positionSuggestions)
                                            <optgroup label="Suggested matches">
                                                @foreach($positionSuggestions as $suggestion)
                                                    @if($positionCandidates->contains('id', $suggestion['id']))
                                                        <option value="{{ $suggestion['id'] }}" @selected((string) $selectedPosition === (string) $suggestion['id'])>{{ $suggestion['title'] }} ({{ $suggestion['score'] }}% match)</option>
                                                    @endif
                                                @endforeach
                                            </optgroup>
                                        @endif
                                        <optgroup label="All existing positions">
                                            @foreach($positionCandidates->reject(fn($position) => in_array($position->id, $suggestedPositionIds, true)) as $position)
                                                <option value="{{ $position->id }}" @selected((string) $selectedPosition === (string) $position->id)>{{ $position->title }}</option>
                                            @endforeach
                                        </optgroup>
                                        @if($departmentId)
                                            <option value="create" @selected($selectedPosition === 'create')>Create position “{{ $pair['position'] }}”</option>
                                        @endif
                                    </select>
                            </label>
                            @endif
                        @endforeach
                    @endif
                    @error('office_source_column')<p class="form-error">{{ $message }}</p>@enderror
                    @error('department_map')<p class="form-error">{{ $message }}</p>@enderror
                    @error('position_map')<p class="form-error">{{ $message }}</p>@enderror
                    <div class="form-actions"><button class="button button-primary">Save matches and validate employees</button></div>
                </form>
            @endif
        </section>
    @endif

    @if($import->field_mappings && $import->status !== 'completed')
        <section class="panel">
            <div class="panel-heading">
                <div><h2>Step 5: Confirm and import</h2><p>{{ $summary['ready_new'] }} new employee(s), {{ $summary['ready_updates'] }} existing employee(s) to update, and {{ $summary['accounts_to_create'] }} new account(s) are ready. Problem rows remain available for correction.</p></div>
            </div>
            @if($summary['valid'] > 0)
                <form method="POST" action="{{ route('employee-imports.confirm', $import) }}" onsubmit="return confirm('Import {{ $summary['ready_new'] }} new employee(s), update {{ $summary['ready_updates'] }} existing employee(s), and create {{ $summary['accounts_to_create'] }} new account(s)?')">
                    @csrf
                    <input type="hidden" name="confirm_import" value="1">
                    <button class="button button-primary">Confirm and import ready records</button>
                </form>
            @elseif($summary['duplicates'] > 0 && $summary['corrections'] === 0 && $summary['failed'] === 0)
                <form method="POST" action="{{ route('employee-imports.confirm', $import) }}" onsubmit="return confirm('Finish this batch without importing any records? All detected records are duplicates.')">
                    @csrf
                    <input type="hidden" name="confirm_import" value="1">
                    <button class="button button-secondary">Finish batch and skip duplicates</button>
                </form>
            @else
                <p class="empty-note">There are no valid records ready to import. Correct the flagged records below and save your changes.</p>
            @endif
        </section>
    @endif

    <section class="panel">
        <div class="panel-heading"><div><h2>Step 4: Validation and corrections</h2><p>Review unmatched departments, positions, missing details, and duplicate records.</p></div></div>
        @forelse($rows as $row)
            <article class="application-row">
                <span class="row-date">Row {{ $row->source_row }}</span>
                <div class="row-main">
                    <b>{{ implode(' ', array_filter($parsedNames[$row->id] ?? [])) ?: 'Name needs review' }}</b>
                    <small>
                        {{ $row->review_data['classification']['department'] ?? 'Department unresolved' }} · {{ $row->review_data['classification']['position'] ?? 'Position unresolved' }} · {{ ucfirst(str_replace('_', ' ', $row->status)) }}
                        @if(!empty($row->review_data['classification']['plantilla_item_no']))
                            · Item {{ $row->review_data['classification']['plantilla_item_no'] }}
                        @endif
                        @if(!empty($row->review_data['classification']['identity_type']) && in_array($row->review_data['classification']['identity_type'], ['item_name', 'item_name_dob'], true))
                            · HRIS will generate an internal employee number; re-import matching uses the item number and employee details.
                        @endif
                    </small>
                    @if($row->issues)
                        <ul>@foreach($row->issues as $issue)<li>{{ $issue }}</li>@endforeach</ul>
                    @endif
                    @if($canEditMappings && !in_array($row->status, ['imported', 'duplicate'], true))
                        @php
                            $correctedName = $row->review_data['name'] ?? [];
                            $correctedEmployee = $row->review_data['employee'] ?? [];
                            $correctedProfile = $row->review_data['profile'] ?? [];
                            $defaults = $correctionDefaults[$row->id];
                        @endphp
                        <details class="top-space">
                            <summary>Correct employee details</summary>
                            <form method="POST" action="{{ route('employee-imports.rows.review', [$import, $row]) }}" class="form-stack top-space">
                                @csrf
                                @method('PUT')
                                <div class="form-grid">
                                    <label>First name *<input name="first_name" value="{{ $correctedName['first_name'] ?? $defaults['name']['first_name'] ?? '' }}" required></label>
                                    <label>Middle name<input name="middle_name" value="{{ $correctedName['middle_name'] ?? $defaults['name']['middle_name'] ?? '' }}"></label>
                                    <label>Last name *<input name="last_name" value="{{ $correctedName['last_name'] ?? $defaults['name']['last_name'] ?? '' }}" required></label>
                                    <label>Suffix<input name="suffix" value="{{ $correctedName['suffix'] ?? $defaults['name']['suffix'] ?? '' }}"></label>
                                    <label>Employee number<input name="employee_no" value="{{ $correctedEmployee['employee_no'] ?? $defaults['employee']['employee_no'] ?? '' }}"><small>For Plantilla records, HRIS generates an internal employee number when this is blank.</small></label>
                                    <label>Email for login (optional)<input type="email" name="email" value="{{ $correctedEmployee['email'] ?? $defaults['employee']['email'] ?? '' }}"><small>HR can add an email later and send a secure activation link.</small></label>
                                    <label>Gender<input name="sex" value="{{ $correctedEmployee['sex'] ?? $defaults['employee']['sex'] ?? '' }}"></label>
                                    <label>Birth date<input type="date" name="date_of_birth" value="{{ $correctedEmployee['date_of_birth'] ?? $defaults['employee']['date_of_birth'] ?? '' }}"></label>
                                    <label>Employment category<input name="source_classification" value="{{ $correctedProfile['source_classification'] ?? $defaults['profile']['source_classification'] ?? '' }}"></label>
                                    <label>Nature of appointment<input name="employment_type" value="{{ $correctedEmployee['employment_type'] ?? $defaults['employee']['employment_type'] ?? '' }}"></label>
                                    <label>Position level<input name="position_level" value="{{ $correctedProfile['position_level'] ?? $defaults['profile']['position_level'] ?? '' }}"></label>
                                </div>
                                @if(!empty($row->review_data['classification']['possible_name_matches']))
                                    <p class="warning-note">Possible existing name matches: {{ collect($row->review_data['classification']['possible_name_matches'])->pluck('full_name')->implode(', ') }}.</p>
                                    <label class="checkbox-label"><input type="checkbox" name="confirm_distinct_employee" value="1" @checked(!empty($correctedEmployee['confirm_distinct_employee']))> I confirm this is a different person from the possible match(es).</label>
                                @endif
                                <button class="button button-secondary">Save corrections and revalidate</button>
                            </form>
                        </details>
                    @endif
                </div>
                <span class="status-tag">{{ match($row->status) {
                    'unvalidated' => 'Awaiting Mapping',
                    'existing_to_update' => 'Existing Employee',
                    'ready' => 'Ready to Import',
                    'ready_to_update' => 'Ready to Update',
                    'needs_review' => 'Needs Correction',
                    'imported' => 'Imported',
                    'updated' => 'Updated',
                    'duplicate' => 'Duplicate to Skip',
                    'failed' => 'Failed',
                    default => ucfirst(str_replace('_', ' ', $row->status)),
                } }}</span>
            </article>
        @empty
            <p class="empty-note">No employee records were found in this workbook.</p>
        @endforelse
        <div class="pagination-wrap">{{ $rows->links() }}</div>
    </section>
@endsection
