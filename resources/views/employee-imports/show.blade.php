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

    @php
        $activeStep = !$import->field_mappings || $summary['awaiting_mapping']
            ? 3
            : (in_array($import->status, ['completed', 'completed_with_errors'], true)
                ? 5
                : (($summary['corrections'] || $summary['failed'] || $summary['possible_duplicates']) ? 4 : 5));
        $statusLabel = match($import->status) {
            'mapping_required' => $import->field_mappings ? 'Mapping required' : 'Analyzed',
            'pending_review' => 'Analyzed',
            'ready_for_import', 'mapping_approved' => $summary['valid'] > 0 ? 'Ready for import' : 'No valid records ready',
            'partially_ready' => 'Partially ready',
            'importing' => 'Importing',
            'completed' => 'Completed',
            'completed_with_errors' => 'Completed with exceptions',
            'review_required' => $summary['corrections'] || $summary['failed']
                ? 'Needs correction'
                : (($summary['awaiting_mapping'] || $summary['mapping_pending']) ? 'Mapping required' : 'No valid records ready'),
            'failed' => 'Failed',
            default => ucfirst(str_replace('_', ' ', $import->status)),
        };
        $attentionItems = [];
        if ($summary['mapping_pending']) {
            $attentionItems[] = [
                'label' => 'Mappings need to be saved',
                'count' => $summary['mapping_pending'],
                'detail' => 'Confirm the department and position choices before validating employee rows.',
                'href' => '#organizational-mapping',
            ];
        }
        if ($summary['unmatched_department_count']) {
            $attentionItems[] = [
                'label' => 'Unmatched departments',
                'count' => $summary['unmatched_department_count'],
                'detail' => 'Choose an existing department or explicitly create an approved one.',
                'href' => '#department-mappings',
            ];
        }
        if ($summary['department_confirmation_count']) {
            $attentionItems[] = [
                'label' => 'Departments awaiting confirmation',
                'count' => $summary['department_confirmation_count'],
                'detail' => 'Review the suggestions and select the correct department.',
                'href' => '#department-mappings',
            ];
        }
        if ($summary['unmatched_position_count']) {
            $attentionItems[] = [
                'label' => 'Unmatched positions',
                'count' => $summary['unmatched_position_count'],
                'detail' => 'Choose a position in the confirmed department or create one from the reference.',
                'href' => '#position-mappings',
            ];
        }
        if ($summary['position_confirmation_count']) {
            $attentionItems[] = [
                'label' => 'Positions awaiting confirmation',
                'count' => $summary['position_confirmation_count'],
                'detail' => 'Review the suggestions; similar titles are not selected automatically.',
                'href' => '#position-mappings',
            ];
        }
        if ($summary['ambiguous_position_count']) {
            $attentionItems[] = [
                'label' => 'Ambiguous position matches',
                'count' => $summary['ambiguous_position_count'],
                'detail' => 'Multiple HRIS positions or conflicting salary grades require a decision.',
                'href' => '#position-mappings',
            ];
        }
        if ($summary['mapping_blocked_records']) {
            $attentionItems[] = [
                'label' => 'Employee records blocked by mappings',
                'count' => $summary['mapping_blocked_records'],
                'detail' => 'Resolve the department or position mapping, then review these affected records.',
                'href' => request()->fullUrlWithQuery(['row_status' => 'awaiting_mapping', 'row_search' => null, 'page' => 1]).'#employee-corrections',
            ];
        }
        if ($summary['awaiting_validation']) {
            $attentionItems[] = [
                'label' => 'Employee records awaiting validation',
                'count' => $summary['awaiting_validation'],
                'detail' => 'Save organizational mappings to validate these rows.',
                'href' => request()->fullUrlWithQuery(['row_status' => 'awaiting_validation', 'row_search' => null, 'page' => 1]).'#employee-corrections',
            ];
        }
        if ($summary['corrections']) {
            $attentionItems[] = [
                'label' => 'Employee records needing correction',
                'count' => $summary['corrections'],
                'detail' => 'Review the listed validation messages and correct only affected records.',
                'href' => request()->fullUrlWithQuery(['row_status' => 'needs_review', 'row_search' => null, 'page' => 1]).'#employee-corrections',
            ];
        }
        if ($summary['possible_duplicates']) {
            $attentionItems[] = [
                'label' => 'Possible duplicate employees',
                'count' => $summary['possible_duplicates'],
                'detail' => 'Compare the possible identity matches before confirming these rows.',
                'href' => request()->fullUrlWithQuery(['row_status' => 'possible_duplicates', 'row_search' => null, 'page' => 1]).'#employee-corrections',
            ];
        }
        if ($summary['duplicates']) {
            $attentionItems[] = [
                'label' => 'Duplicate records to skip',
                'count' => $summary['duplicates'],
                'detail' => 'These records will not create employees or accounts.',
                'href' => request()->fullUrlWithQuery(['row_status' => 'duplicate', 'row_search' => null, 'page' => 1]).'#employee-corrections',
            ];
        }
        if ($summary['failed']) {
            $attentionItems[] = [
                'label' => 'Validation or import errors',
                'count' => $summary['failed'],
                'detail' => 'Review the failure message before retrying this batch.',
                'href' => request()->fullUrlWithQuery(['row_status' => 'failed', 'row_search' => null, 'page' => 1]).'#employee-corrections',
            ];
        }
    @endphp

    <nav class="import-progress" aria-label="Employee import progress">
        <ol>
            @foreach([
                1 => ['Upload', route('employee-imports.index')],
                2 => ['Analyze', '#analysis'],
                3 => ['Match departments and positions', '#organizational-mapping'],
                4 => ['Review records', '#employee-corrections'],
                5 => ['Final review and import', '#final-review'],
            ] as $step => [$label, $href])
                <li class="{{ $step < $activeStep ? 'is-complete' : ($step === $activeStep ? 'is-current' : '') }}">
                    <a href="{{ $href }}" @if($step === $activeStep) aria-current="step" @endif>
                        <span>{{ $step }}</span>{{ $label }}
                    </a>
                </li>
            @endforeach
        </ol>
    </nav>

    <section class="panel needs-attention" aria-labelledby="needs-attention-title">
        <div class="panel-heading">
            <div>
                <h2 id="needs-attention-title">Needs Attention</h2>
                <p>Jump directly to mappings or employee records that need action.</p>
            </div>
        </div>
        @if($attentionItems)
            <div class="attention-grid">
                @foreach($attentionItems as $item)
                    <a class="attention-item" href="{{ $item['href'] }}">
                        <span><b>{{ $item['count'] }}</b><strong>{{ $item['label'] }}</strong></span>
                        <small>{{ $item['detail'] }}</small>
                    </a>
                @endforeach
            </div>
        @else
            <p class="success-note">No further review is required for this import.</p>
        @endif
    </section>

    <section class="panel" id="analysis">
        <div class="panel-heading">
            <div>
                <h2>Step 2: Automatic analysis</h2>
                <p>{{ $import->original_name }} · {{ $summary['total'] }} employee record(s) detected on {{ $import->worksheet_name ?: 'the employee' }} worksheet (headers on row {{ $import->header_row ?: 1 }})</p>
                @if($import->worksheet_names)
                    <details class="top-space"><summary>{{ count($import->worksheet_names) }} worksheets recognized</summary><p>{{ implode(' · ', $import->worksheet_names) }}</p></details>
                @endif
            </div>
            <span class="status-tag">{{ $statusLabel }}</span>
        </div>
        <div class="import-summary-grid">
            <article class="import-summary-group">
                <h3>Import Overview</h3>
                <div class="summary-primary"><b>{{ $summary['importable'] }}</b><span>Total importable records</span></div>
                <ul class="summary-list">
                    <li>Total employee records <b>{{ $summary['total'] }}</b></li>
                    <li>Valid new employees <b>{{ $summary['ready_new'] }}</b></li>
                    <li>Existing employees to update <b>{{ $summary['ready_updates'] }}</b></li>
                    <li>Accounts to create <b>{{ $summary['accounts_to_create'] }}</b></li>
                    <li>Duplicates to skip <b>{{ $summary['duplicates'] }}</b></li>
                    <li>Awaiting department mapping <b>{{ $summary['awaiting_department_mapping'] }}</b></li>
                    <li>Awaiting position mapping <b>{{ $summary['awaiting_position_mapping'] }}</b></li>
                    <li>Records needing correction <b>{{ $summary['corrections'] }}</b></li>
                </ul>
            </article>
            <article class="import-summary-group">
                <h3>Organizational Matching</h3>
                @if($summary['departments'])
                    <div class="summary-line"><span>Departments matched</span><b>{{ $summary['matched_departments'] }} / {{ $summary['departments'] }}</b></div>
                @endif
                @if($summary['positions_detected'])
                    <div class="summary-line"><span>Positions matched</span><b>{{ $summary['matched_positions'] }} / {{ $summary['positions_detected'] }}</b></div>
                @endif
                <details>
                    <summary>Matching details</summary>
                    <ul class="summary-list">
                        @if($summary['unmatched_department_count'])<li>Unmatched departments <b>{{ $summary['unmatched_department_count'] }}</b></li>@endif
                        @if($summary['department_confirmation_count'])<li>Departments needing confirmation <b>{{ $summary['department_confirmation_count'] }}</b></li>@endif
                        @if($summary['unmatched_position_count'])<li>Unmatched positions <b>{{ $summary['unmatched_position_count'] }}</b></li>@endif
                        @if($summary['position_confirmation_count'])<li>Positions needing confirmation <b>{{ $summary['position_confirmation_count'] }}</b></li>@endif
                        @if($summary['ambiguous_position_count'])<li>Ambiguous position matches <b>{{ $summary['ambiguous_position_count'] }}</b></li>@endif
                        @if($summary['mapping_blocked_records'])<li>Employee records blocked by mappings <b>{{ $summary['mapping_blocked_records'] }}</b></li>@endif
                        @if($summary['position_reference_count'])<li>Titles listed in Positions reference <b>{{ $summary['reference_position_matches'] }} / {{ $summary['position_reference_count'] }}</b></li>@endif
                        @if(!$summary['departments'] && !$summary['positions_detected'])<li>Select an organizational unit column to analyze matches.</li>@endif
                    </ul>
                </details>
            </article>
            <article class="import-summary-group">
                <h3>Data Quality</h3>
                @if($summary['corrections'])
                    <div class="summary-primary"><b>{{ $summary['corrections'] }}</b><span>Records needing correction</span></div>
                @else
                    <p class="summary-empty">No employee correction errors.</p>
                @endif
                <details>
                    <summary>Quality details</summary>
                    <ul class="summary-list">
                        @if($summary['duplicates'])<li>Duplicate records to skip <b>{{ $summary['duplicates'] }}</b></li>@endif
                        @if($summary['failed'])<li>Failed records <b>{{ $summary['failed'] }}</b></li>@endif
                        @if($summary['vacancies'])<li>Vacant plantilla positions excluded <b>{{ $summary['vacancies'] }}</b></li>@endif
                        @if($summary['excluded_offices'])<li>PNP/BFP records excluded <b>{{ $summary['excluded_offices'] }}</b></li>@endif
                        @if(!$summary['duplicates'] && !$summary['failed'] && !$summary['vacancies'] && !$summary['excluded_offices'])<li>No additional data-quality information.</li>@endif
                    </ul>
                </details>
            </article>
            <article class="import-summary-group">
                <h3>Import Results</h3>
                @if($summary['imported'] || $summary['updated'])
                    <div class="summary-primary"><b>{{ $summary['imported'] }}</b><span>Successfully imported</span></div>
                    @if($summary['updated'])<div class="summary-line"><span>Employee records updated</span><b>{{ $summary['updated'] }}</b></div>@endif
                @else
                    <p class="summary-empty">No records have been imported yet.</p>
                @endif
                <details>
                    <summary>Result details</summary>
                    <ul class="summary-list">
                        @if($summary['accounts_created'])<li>Accounts created <b>{{ $summary['accounts_created'] }}</b></li>@endif
                        @if($summary['accounts_to_create'])<li>Accounts to create <b>{{ $summary['accounts_to_create'] }}</b></li>@endif
                        @if($summary['remaining'])<li>Records remaining <b>{{ $summary['remaining'] }}</b></li>@endif
                        @if($summary['duplicates'])<li>Duplicate records to skip <b>{{ $summary['duplicates'] }}</b></li>@endif
                        @if(!$summary['accounts_created'] && !$summary['accounts_to_create'] && !$summary['remaining'] && !$summary['duplicates'])<li>No additional import results.</li>@endif
                    </ul>
                </details>
            </article>
        </div>
        <p class="info-note top-space">Email is optional during import. New employee accounts use the configured temporary password and must change it at first sign-in before accessing HRIS modules. Re-importing an employee preserves the linked account's existing password, password-change status, and permissions. HR sends a password setup link when an email is available. Protected Admin accounts are not changed. Suggested Office Head and Mayor roles require explicit HR Admin review.</p>
    </section>

    @if($canEditMappings)
        <section class="panel" id="organizational-mapping">
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
                        <h3 id="department-mappings" class="import-anchor">Departments</h3>
                        <p class="muted">Each distinct office is matched against approved departments. A new department is added only when an admin explicitly authorizes expanding the approved list.</p>
                        @foreach($officeValues as $office)
                            @php
                                $key = hash('sha256', $office);
                                $autoDepartment = $mappingSummary['department_map'][$key] ?? '';
                                $oldDepartment = old("department_map.{$key}");
                                $selectedDepartment = $oldDepartment === null || ($oldDepartment === '' && $autoDepartment !== '')
                                    ? $autoDepartment
                                    : $oldDepartment;
                                $matchedDepartment = $departments->firstWhere('id', (int) $selectedDepartment);
                                $departmentMatch = $mappingSummary['department_matches'][$key] ?? [];
                                $departmentSuggestions = $mappingSummary['department_suggestions'][$key] ?? [];
                                $selectedDepartmentId = filter_var($selectedDepartment, FILTER_VALIDATE_INT);
                                if ($selectedDepartmentId && $selectedDepartmentId !== (int) ($departmentMatch['department_id'] ?? 0)) {
                                    $departmentMatch['status'] = 'Needs HR Confirmation';
                                    $departmentMatch['reason'] = 'The selected department differs from the automatic match; save to confirm this choice';
                                }
                            @endphp
                            <div class="mapping-row">
                                <span>
                                    <b>{{ $office }}</b>
                                    <small>
                                        {{ $selectedDepartment === 'create' ? 'Needs HR Confirmation' : ($departmentMatch['status'] ?? 'Unmatched') }}
                                        · Matched HRIS department: {{ $matchedDepartment?->name ?? 'None' }}
                                        @if($selectedDepartment === 'create')
                                            · A new department will be created only after explicit authorization and saving.
                                        @elseif(!empty($departmentMatch['reason']))
                                            · {{ $departmentMatch['reason'] }}
                                        @endif
                                        @if(isset($departmentMatch['confidence'])) · {{ $departmentMatch['confidence'] }}% confidence @endif
                                    </small>
                                            @if(!empty($departmentMatch['affected_records']))
                                                <details class="top-space"><summary>View {{ count($departmentMatch['affected_records']) }} affected employee record(s)</summary>
                                                    <ul>@foreach($departmentMatch['affected_records'] as $record)<li>Row {{ $record['source_row'] }} — {{ $record['name'] }}</li>@endforeach</ul>
                                                </details>
                                            @endif
                                </span>
                                <label>
                                    <span class="sr-only">Department for {{ $office }}</span>
                                    <select name="department_map[{{ $key }}]" onchange="document.getElementById('department-create-{{ $key }}')?.toggleAttribute('open', this.value === 'create')">
                                    <option value="">Needs HR confirmation — no department selected</option>
                                    @if($departmentSuggestions)
                                        <optgroup label="Possible matches — HR confirmation required">
                                            @foreach($departmentSuggestions as $suggestion)
                                                <option value="{{ $suggestion['id'] }}" @selected((string) $selectedDepartment === (string) $suggestion['id'])>{{ $suggestion['name'] }} ({{ $suggestion['score'] }}% similarity)</option>
                                            @endforeach
                                        </optgroup>
                                    @endif
                                    <optgroup label="All existing approved departments">
                                        @foreach($departments as $department)
                                            <option value="{{ $department->id }}" @selected((string) $selectedDepartment === (string) $department->id)>{{ $department->name }}{{ $department->code ? ' ('.$department->code.')' : '' }}</option>
                                        @endforeach
                                    </optgroup>
                                    @if(empty($departmentMatch['department_id']))
                                        <option value="create" @selected($selectedDepartment === 'create')>+ Create New Department</option>
                                    @endif
                                    </select>
                                </label>
                                @if(empty($departmentMatch['department_id']) || $selectedDepartment === 'create')
                                    <details id="department-create-{{ $key }}" class="mapping-create" @if($selectedDepartment === 'create' || old("new_departments.{$key}.name") !== null) open @endif>
                                        <summary>Create New Department</summary>
                                        <p class="muted">This department will become an approved import target. Department descriptions are not supported by the current database schema.</p>
                                        <div class="form-grid top-space">
                                            <label>Department name<input name="new_departments[{{ $key }}][name]" maxlength="150" value="{{ old("new_departments.{$key}.name", $office) }}"></label>
                                            <label>Department code (optional)<input name="new_departments[{{ $key }}][code]" maxlength="20" value="{{ old("new_departments.{$key}.code") }}"></label>
                                            <label class="field-span"><span><input type="checkbox" name="new_departments[{{ $key }}][authorize]" value="1" @checked(old("new_departments.{$key}.authorize"))> I explicitly authorize adding this department to the approved import list.</span></label>
                                        </div>
                                        @error("new_departments.{$key}.name")<small class="form-error">{{ $message }}</small>@enderror
                                        @error("new_departments.{$key}.code")<small class="form-error">{{ $message }}</small>@enderror
                                        @error("new_departments.{$key}.authorize")<small class="form-error">{{ $message }}</small>@enderror
                                    </details>
                                @endif
                                @error("department_map.{$key}")<small class="form-error">{{ $message }}</small>@enderror
                            </div>
                        @endforeach
                    @else
                        <p class="empty-note">No office values were found in this column. Select the column containing employee department names before saving.</p>
                    @endif

                    @if($positionPairs->isNotEmpty())
                        <h3 id="position-mappings" class="top-space import-anchor">Positions</h3>
                        <p class="muted">Active HRIS position records available for matching: {{ $positions->count() }}. Existing positions are selected automatically. Unmatched titles can be linked to an existing position or explicitly added to that department after HR review.</p>
                        @foreach($positionPairs as $pair)
                            @php
                                $key = hash('sha256', $pair['office']."\0".$pair['position']);
                                $autoPosition = $mappingSummary['position_map'][$key] ?? '';
                                $oldPosition = old("position_map.{$key}");
                                $selectedPosition = $oldPosition === null || ($oldPosition === '' && $autoPosition !== '')
                                    ? $autoPosition
                                    : $oldPosition;
                                $departmentKey = hash('sha256', $pair['office']);
                                $autoDepartmentId = $mappingSummary['department_map'][$departmentKey] ?? null;
                                $oldDepartmentId = old("department_map.{$departmentKey}");
                                $departmentId = $oldDepartmentId === null || ($oldDepartmentId === '' && $autoDepartmentId !== null)
                                    ? $autoDepartmentId
                                    : $oldDepartmentId;
                                $departmentId = filter_var($departmentId, FILTER_VALIDATE_INT) ?: null;
                                $departmentWillBeCreated = $oldDepartmentId === 'create';
                                $positionCandidates = $departmentWillBeCreated
                                    ? $positions->take(0)
                                    : $positions->filter(fn($position) => !$departmentId || !$position->department_id || (int)$position->department_id === (int)$departmentId);
                                $matchedPosition = $selectedPosition !== 'create'
                                    ? $positionCandidates->firstWhere('id', (int) $selectedPosition)
                                    : null;
                                $positionSuggestions = $departmentWillBeCreated ? [] : ($mappingSummary['position_suggestions'][$key] ?? []);
                                $positionMatch = $mappingSummary['position_matches'][$key] ?? [];
                                $automaticReference = ($positionMatch['status'] ?? null) === 'Official Reference Match';
                                $suggestedPositionIds = collect($positionSuggestions)->pluck('id')->all();
                                $selectedPositionId = filter_var($selectedPosition, FILTER_VALIDATE_INT);
                                $hasConfirmedMatch = $selectedPositionId
                                    && $selectedPositionId === (int) ($positionMatch['position_id'] ?? 0);
                                if ($selectedPositionId && ! $hasConfirmedMatch) {
                                    $positionMatch['status'] = 'Needs HR Confirmation';
                                    $positionMatch['reason'] = 'The selected position differs from the automatic match; save to confirm this choice';
                                }
                                $showCreatePosition = ! $hasConfirmedMatch
                                    && empty($positionMatch['has_exact_match'])
                                    && ! $automaticReference;
                            @endphp
                            <div class="mapping-row">
                                <span>
                                    <b>{{ $pair['position'] }}</b>
                                    <small>
                                        {{ $pair['office'] }} · {{ $positionMatch['status'] ?? 'Unmatched' }}
                                        · @if($automaticReference)
                                            Official reference title to add: {{ $positionMatch['position_title'] }}
                                        @else
                                            Matched HRIS position: {{ $matchedPosition?->title ?? 'None' }}
                                        @endif
                                        @if(!empty($positionMatch['reason'])) · {{ $positionMatch['reason'] }}@endif
                                        @if(isset($positionMatch['confidence'])) · {{ $positionMatch['confidence'] }}% confidence @endif
                                        @if($import->position_reference_count > 0) · {{ !empty($mappingSummary['reference_positions'][$key]) ? 'Listed on Positions worksheet' : 'Not found in Positions worksheet' }}@endif
                                        @if(count($pair['salary_grades'] ?? []) > 1) · Workbook has multiple salary grades: {{ implode(', ', $pair['salary_grades']) }}@endif
                                    </small>
                                    @if(!empty($pair['affected_records']))
                                        <details class="top-space"><summary>View {{ count($pair['affected_records']) }} affected employee record(s)</summary>
                                            <ul>@foreach($pair['affected_records'] as $record)<li>Row {{ $record['source_row'] }} — {{ $record['name'] }}</li>@endforeach</ul>
                                        </details>
                                    @endif
                                </span>
                                <label>
                                    <span class="sr-only">Position for {{ $pair['position'] }} in {{ $pair['office'] }}</span>
                                    <select name="position_map[{{ $key }}]" onchange="document.getElementById('position-create-{{ $key }}')?.toggleAttribute('open', this.value === 'create')">
                                    <option value="">{{ $automaticReference ? 'Official reference title will be added when mappings are saved' : 'Needs HR confirmation — no position selected' }}</option>
                                    @if($positionSuggestions)
                                        <optgroup label="Possible matches — HR confirmation required">
                                            @foreach($positionSuggestions as $suggestion)
                                                @if($positionCandidates->contains('id', $suggestion['id']))
                                                    <option value="{{ $suggestion['id'] }}" @selected((string) $selectedPosition === (string) $suggestion['id'])>{{ $suggestion['title'] }} — {{ $suggestion['department'] ?? 'Any department' }}@if(!empty($suggestion['salary_grade'])) — SG {{ $suggestion['salary_grade'] }}@endif ({{ $suggestion['score'] }}% similarity)</option>
                                                @endif
                                            @endforeach
                                        </optgroup>
                                    @endif
                                    <optgroup label="{{ $departmentId ? 'Existing positions valid for this department' : 'Existing positions — confirm department association' }}">
                                        @foreach($positionCandidates->reject(fn($position) => in_array($position->id, $suggestedPositionIds, true)) as $position)
                                            <option value="{{ $position->id }}" @selected((string) $selectedPosition === (string) $position->id)>{{ $position->title }} — {{ $position->department?->name ?? 'Any department' }}</option>
                                        @endforeach
                                    </optgroup>
                                    @if($showCreatePosition)
                                        <option value="create" @selected($selectedPosition === 'create')>Create position “{{ $pair['position'] }}”</option>
                                    @endif
                                    </select>
                                </label>
                                @if($showCreatePosition && ($departmentId || $departmentWillBeCreated))
                                    <details id="position-create-{{ $key }}" class="mapping-create" @if($selectedPosition === 'create' || old("new_positions.{$key}.title") !== null) open @endif>
                                        <summary>+ Create New Position</summary>
                                        <p class="muted">
                                            Imported title: {{ $pair['position'] }} · Source department: {{ $pair['office'] }} · Department: {{ $departmentWillBeCreated ? 'the new department above' : ($departments->firstWhere('id', $departmentId)?->name ?? 'select an existing department above') }} · Affected employee records: {{ count($pair['affected_records'] ?? []) }}.
                                            Status: Active (required for this import). Plantilla item numbers and employment classifications remain on employee records; neither is a field on the current positions table. Remarks are not supported by the current positions schema.
                                        </p>
                                        <div class="form-grid top-space">
                                            <label>Position title<input name="new_positions[{{ $key }}][title]" maxlength="150" value="{{ old("new_positions.{$key}.title", $pair['position']) }}"></label>
                                            <label>Salary grade<input name="new_positions[{{ $key }}][salary_grade]" maxlength="10" value="{{ old("new_positions.{$key}.salary_grade", $pair['salary_grade'] ?? '') }}"></label>
                                        </div>
                                        <button class="button button-secondary top-space" type="submit" name="create_position[{{ $key }}]" value="1">Create and map this position</button>
                                        @error("new_positions.{$key}.title")<small class="form-error">{{ $message }}</small>@enderror
                                        @error("new_positions.{$key}.salary_grade")<small class="form-error">{{ $message }}</small>@enderror
                                    </details>
                                @elseif($showCreatePosition)
                                    <p class="muted">Confirm a department mapping before creating this position.</p>
                                @endif
                                @error("position_map.{$key}")<small class="form-error">{{ $message }}</small>@enderror
                            </div>
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

    <section class="panel" id="employee-corrections">
        <div class="panel-heading">
        <div><h2>Step 4: Review employee records</h2><p>Use the filters to review role suggestions, corrections, possible duplicates, or specific source records before importing.</p></div>
        <a class="text-link" href="#needs-attention-title">Back to Needs Attention</a>
        </div>
        @if($errors->has('role') || $errors->has('reason'))
            <div class="warning-note">{{ $errors->first('role') ?: $errors->first('reason') }}</div>
        @endif
        <form method="GET" action="{{ route('employee-imports.show', $import) }}" class="filter-bar import-row-filters">
            @if($selectedOfficeColumn)<input type="hidden" name="office_column" value="{{ $selectedOfficeColumn }}">@endif
            <label>Record status
                <select name="row_status">
                    @foreach([
                        'needs_attention' => 'Needs attention',
                        'all' => 'All records',
                        'awaiting_validation' => 'Awaiting validation',
                        'awaiting_mapping' => 'Awaiting department or position mapping',
                        'needs_review' => 'Needs correction',
                        'possible_duplicates' => 'Possible duplicate employees',
                        'role_review' => 'Imported employee accounts',
                        'duplicate' => 'Duplicate records',
                        'failed' => 'Failed records',
                        'ready' => 'Ready to import',
                        'ready_to_update' => 'Ready to update',
                        'imported' => 'Imported',
                        'updated' => 'Updated',
                    ] as $value => $label)
                        <option value="{{ $value }}" @selected($rowStatus === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label>Search employee or source values
                <input type="search" name="row_search" value="{{ $rowSearch }}" maxlength="100" placeholder="Name, employee number, item number">
            </label>
            <button type="submit" class="button button-secondary">Filter records</button>
        </form>
        <p class="muted import-row-count">Showing {{ $rows->count() }} of {{ $rows->total() }} matching record(s) · {{ $summary['awaiting_validation'] }} awaiting validation · {{ $summary['corrections'] }} needing correction · {{ $summary['duplicates'] }} duplicates · {{ $summary['failed'] }} failed</p>
        <div class="table-wrap employee-import-role-table-wrap">
            <table class="employee-import-role-table">
                <thead>
                    <tr>
                        <th>Employee Name</th>
                        <th>Employee Number</th>
                        <th>Position</th>
                        <th>Department</th>
                        <th>Suggested System Role</th>
                        <th>Confirmed System Role</th>
                        <th>Import Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $row)
                        @php
                            $roleState = $rowReviewStates[$row->id]['role'] ?? [];
                            $roleLabels = ['admin' => 'HR Admin', 'office_head' => 'Office Head', 'mayor' => 'Mayor', 'employee' => 'Employee'];
                            $suggestedRole = $roleState['suggested_role'] ?? 'employee';
                            $confirmedRole = $roleState['confirmed_role'] ?? 'employee';
                        @endphp
                        <tr>
                            <td><b>{{ implode(' ', array_filter($parsedNames[$row->id] ?? [])) ?: 'Name needs review' }}</b></td>
                            <td>{{ $roleState['employee_number'] ?? 'Assigned on import' }}</td>
                            <td>{{ $roleState['position'] ?? 'Position unresolved' }}</td>
                            <td>{{ $roleState['department'] ?? 'Department unresolved' }}</td>
                            <td><span class="role-badge role-badge-{{ $suggestedRole }}">{{ $roleLabels[$suggestedRole] ?? 'Employee' }}</span></td>
                            <td>
                                <span class="role-badge role-badge-{{ $confirmedRole }}">{{ $roleLabels[$confirmedRole] ?? 'Employee' }}</span>
                                @if(isset($row->review_data['role_review']['confirmed_role']))<small class="role-review-note">HR Admin reviewed</small>@endif
                                @if(!empty($roleState['role_conflict']))<p class="warning-note">{{ $roleState['role_conflict'] }}</p>@endif
                                @if(in_array($row->status, ['imported', 'updated'], true) && $row->employee?->user && !$row->employee->user->isAdministrator())
                                    <details class="top-space">
                                        <summary>Review role</summary>
                                        <form method="POST" action="{{ route('employee-imports.rows.role', [$import, $row]) }}" class="form-stack top-space">
                                            @csrf
                                            @method('PUT')
                                            <label>Confirmed role
                                                <select name="role" required>
                                                    @foreach(['employee' => 'Employee', 'office_head' => 'Office Head', 'mayor' => 'Mayor'] as $value => $label)
                                                        <option value="{{ $value }}" @selected($confirmedRole === $value)>{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            </label>
                                            <label>Reason for role decision
                                                <textarea name="reason" rows="2" maxlength="1000" required>{{ old('reason') }}</textarea>
                                            </label>
                                            <button class="button button-secondary">Save role review</button>
                                        </form>
                                    </details>
                                @endif
                            </td>
                            <td><span class="status-tag">{{ ucfirst(str_replace('_', ' ', $row->status)) }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="empty-note">No employee records match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @forelse($rows as $row)
            @php
                $reviewState = $rowReviewStates[$row->id] ?? [];
                $roleState = $reviewState['role'] ?? [];
                $confirmedRole = $roleState['confirmed_role'] ?? 'employee';
            @endphp
            <article class="application-row" id="employee-row-{{ $row->id }}">
                <span class="row-date">Row {{ $row->source_row }}</span>
                <div class="row-main">
                    <b>{{ implode(' ', array_filter($parsedNames[$row->id] ?? [])) ?: 'Name needs review' }}</b>
                    <small>
                        {{ $row->review_data['classification']['department'] ?? 'Department unresolved' }} · {{ $row->review_data['classification']['position'] ?? 'Position unresolved' }} ·
                        @if($row->status === 'needs_review')
                            {{ !empty($reviewState['awaiting_mapping']) && !empty($reviewState['needs_correction'])
                                ? 'Needs Mapping and Correction'
                                : (!empty($reviewState['awaiting_mapping']) ? 'Awaiting Mapping' : 'Needs Correction') }}
                        @else
                            {{ ucfirst(str_replace('_', ' ', $row->status)) }}
                        @endif
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
                                    <label>Confirmed role
                                        <select name="role" required>
                                            @foreach(['employee' => 'Employee', 'office_head' => 'Office Head', 'mayor' => 'Mayor'] as $value => $label)
                                                <option value="{{ $value }}" @selected($confirmedRole === $value)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </label>
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
                    'unvalidated' => 'Awaiting Validation',
                    'existing_to_update' => 'Awaiting Validation · Existing Employee',
                    'ready' => 'Ready to Import',
                    'ready_to_update' => 'Ready to Update',
                    'needs_review' => !empty($reviewState['awaiting_mapping'])
                        ? (!empty($reviewState['needs_correction']) ? 'Needs Mapping and Correction' : 'Awaiting Mapping')
                        : 'Needs Correction',
                    'imported' => 'Imported',
                    'updated' => 'Updated',
                    'duplicate' => 'Duplicate · Will Be Skipped',
                    'failed' => 'Failed',
                    default => ucfirst(str_replace('_', ' ', $row->status)),
                } }}</span>
            </article>
        @empty
            <p class="empty-note">No employee records match these filters.</p>
        @endforelse
        <div class="pagination-wrap">{{ $rows->links() }}</div>
    </section>

    @if($import->field_mappings && $import->status !== 'completed')
        <section class="panel" id="final-review">
            <div class="panel-heading">
                <div>
                    <h2>Step 5: Final review and import</h2>
                    <p>{{ $summary['total'] }} total employee record(s): {{ $summary['ready_new'] }} valid new employee(s), {{ $summary['ready_updates'] }} existing employee(s) to update, and {{ $summary['accounts_to_create'] }} account(s) to create. {{ $summary['duplicates'] }} duplicate(s) will be skipped; {{ $summary['unresolved_records'] }} unresolved record(s) will remain saved for review. {{ $summary['importable'] }} validated record(s) can be imported now.</p>
                </div>
            </div>
            @if($import->status === 'importing' || $summary['pending_setup_links'] > 0)
                <p class="success-note">
                    @if($import->status === 'importing')
                        This import is being processed in batches. {{ $summary['imported'] + $summary['updated'] }} of {{ $summary['total'] }} employee record(s) have been saved so far.
                    @else
                        Employee records are saved; account setup links are continuing in batches.
                    @endif
                    This workflow resumes automatically if this page is reloaded.
                </p>
                <form id="continue-employee-import" method="POST" action="{{ route('employee-imports.confirm', $import) }}" onsubmit="const button = this.querySelector('button'); button.disabled = true; button.textContent = 'Importing…';">
                    @csrf
                    <input type="hidden" name="confirm_import" value="1">
                    <button class="button button-primary">{{ $import->status === 'importing' ? 'Continue import' : 'Continue account setup links' }}</button>
                </form>
                <script>
                    document.addEventListener('DOMContentLoaded', () => {
                        const form = document.getElementById('continue-employee-import');
                        if (form) window.setTimeout(() => form.requestSubmit(), 250);
                    });
                </script>
            @elseif($summary['valid'] > 0)
                <form method="POST" action="{{ route('employee-imports.confirm', $import) }}" onsubmit="if (!confirm('Import {{ $summary['ready_new'] }} new employee(s), update {{ $summary['ready_updates'] }} existing employee(s), and create {{ $summary['accounts_to_create'] }} new account(s)? Unresolved rows and duplicates will not be imported.')) return false; const button = this.querySelector('button'); button.disabled = true; button.textContent = 'Importing…';">
                    @csrf
                    <input type="hidden" name="confirm_import" value="1">
                    <button class="button button-primary">Confirm and import ready records</button>
                </form>
            @elseif($summary['duplicates'] > 0 && $summary['corrections'] === 0 && $summary['failed'] === 0)
                <form method="POST" action="{{ route('employee-imports.confirm', $import) }}" onsubmit="if (!confirm('Finish this batch without importing any records? All detected records are duplicates.')) return false; const button = this.querySelector('button'); button.disabled = true; button.textContent = 'Finishing batch…';">
                    @csrf
                    <input type="hidden" name="confirm_import" value="1">
                    <button class="button button-secondary">Finish batch and skip duplicates</button>
                </form>
            @elseif($summary['awaiting_validation'] > 0)
                <p class="empty-note">Save the organizational mappings to validate employee records before importing.</p>
            @else
                <p class="empty-note">There are no valid records ready to import. Resolve the flagged records above and save your changes.</p>
            @endif
        </section>
    @endif
@endsection
