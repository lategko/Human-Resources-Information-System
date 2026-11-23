@extends('layouts.app')
@section('title', 'Attendance')
@section('content')
    @php
        $scopeRecords = $records->getCollection();
    @endphp
    <div class="page-heading">
        <div>
            <span class="eyebrow">TIME AND ATTENDANCE</span>
            <h1>{{ auth()->user()->isEmployee() ? 'My attendance' : 'Attendance monitoring' }}</h1>
            <p>GPS-verified attendance using approved municipal workplaces and work schedules.</p>
        </div>
    </div>

    @if(auth()->user()->isEmployee())
        @php
            $resolvedToday = $todayRecord
                ? ['schedule' => $todayRecord->schedule_snapshot ?? \App\Models\WorkSchedule::resolveFor(auth()->user()->employee, today()->toDateString())['schedule']]
                : ['schedule' => \App\Models\WorkSchedule::resolveFor(auth()->user()->employee, today()->toDateString())['schedule']];
            $schedule = $resolvedToday['schedule'];
        @endphp
        <section class="panel gps-attendance-panel" x-data="gpsAttendance('{{ route('attendance.time-in') }}', '{{ route('attendance.time-out') }}')">
            <div class="panel-heading">
                <div>
                    <h2>Today's attendance</h2>
                    <p>{{ today()->format('l, F j, Y') }}</p>
                </div>
                <span class="status-tag status-{{ $todayRecord ? \Illuminate\Support\Str::slug($todayRecord->status) : 'submitted' }}">
                    {{ $todayRecord?->status ?? 'Not recorded' }}
                </span>
            </div>
            <div class="gps-attendance-grid">
                <div><small>Applicable schedule</small><b>{{ substr($schedule['start_time'], 0, 5) }}–{{ substr($schedule['end_time'], 0, 5) }}</b></div>
                <div><small>Time in</small><b>{{ $todayRecord?->time_in ? substr($todayRecord->time_in, 0, 5) : 'Not recorded' }}</b></div>
                <div><small>Time out</small><b>{{ $todayRecord?->time_out ? substr($todayRecord->time_out, 0, 5) : 'Not recorded' }}</b></div>
                <div><small>Location verification</small><b>{{ $todayRecord?->gps_verification_status ?? 'Pending verification' }}</b></div>
            </div>
            @if($todayRecord?->time_in_workplace_id)
                <p class="gps-verification-result">
                    Verified workplace: {{ $todayRecord->timeInWorkplace?->name ?? 'Authorized workplace' }}
                    @if($todayRecord->time_in_accuracy_meters) · Accuracy {{ number_format($todayRecord->time_in_accuracy_meters, 1) }} m · Distance {{ number_format($todayRecord->time_in_distance_meters, 1) }} m @endif
                </p>
            @endif
            <div class="gps-action-grid">
                <button type="button" class="button button-primary" @click="submit('time-in')" :disabled="busy || {{ $todayRecord?->time_in ? 'true' : 'false' }}">TIME IN</button>
                <button type="button" class="button button-outline" @click="submit('time-out')" :disabled="busy || {{ !$todayRecord?->time_in || $todayRecord?->time_out ? 'true' : 'false' }}">TIME OUT</button>
            </div>
            <p class="gps-status-message" x-text="message" role="status"></p>
            <p class="privacy-note">Location is collected only when you initiate Time In or Time Out. It is used to verify your attendance at an authorized workplace and retained with the attendance record for authorized HR review.</p>
        </section>

        <section class="panel">
            <div class="panel-heading"><div><h2>Request an attendance exception</h2><p>Technical problems do not create or alter attendance automatically.</p></div></div>
            <form method="POST" action="{{ route('attendance.exceptions.store') }}" enctype="multipart/form-data" class="form-grid top-space" data-confirm-type="form" data-confirm-title="Submit Attendance Correction Request?" data-confirm-message="Send this attendance exception request and explanation to HR for review?" data-confirm-text="Submit Request">
                @csrf
                <label>Attendance date *<input type="date" name="attendance_date" value="{{ old('attendance_date', today()->toDateString()) }}" required></label>
                <label>Requested action *<select name="requested_action" required><option value="time_in">Time in</option><option value="time_out">Time out</option><option value="correction">Correction</option></select></label>
                <label class="field-span">Explanation *<textarea name="explanation" rows="3" maxlength="2000" required>{{ old('explanation') }}</textarea></label>
                <label class="field-span">Supporting document<input type="file" name="supporting_document" accept=".pdf,.jpg,.jpeg,.png"></label>
                <button class="button button-primary">Submit exception</button>
            </form>
        </section>
    @endif

    <section class="panel attendance-records-panel">
        <form class="filter-bar attendance-filters" method="GET">
            <label>Work date<input type="date" name="date" value="{{ request('date') }}"></label>
            @if(!auth()->user()->isOfficeHead() && !auth()->user()->isEmployee())
                <select name="department_id"><option value="">All departments</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected(request('department_id') == $department->id)>{{ $department->name }}</option>@endforeach</select>
            @endif
            @if(auth()->user()->isAdministrator())
                <select name="employee_id"><option value="">All employees</option>@foreach($employees as $employee)<option value="{{ $employee->id }}" @selected(request('employee_id') == $employee->id)>{{ $employee->employee_no }} · {{ $employee->full_name }}</option>@endforeach</select>
            @endif
            <label class="search-field"><span>⌕</span><input name="q" value="{{ request('q') }}" placeholder="Employee ID or last name"></label>
            <select name="status"><option value="">All statuses</option>@foreach(['Present','Late','Absent','On Leave','On Approved Leave','Official Business','Half Day','Rest Day','Holiday','Incomplete'] as $status)<option @selected(request('status') === $status)>{{ $status }}</option>@endforeach</select>
            @if(!auth()->user()->isEmployee())
                <select name="gps_status"><option value="">All GPS verification</option>@foreach(['Verified','Outside Area','Insufficient Accuracy','Permission Denied','Unavailable','For Review'] as $status)<option @selected(request('gps_status') === $status)>{{ $status }}</option>@endforeach</select>
            @endif
            <button type="submit" class="button button-outline">Filter</button>
        </form>

        @if(auth()->user()->isAdministrator())
            @php
                $totalActive = \App\Models\Employee::whereNull('archived_at')->where('employment_status','Active')->count();
                $present = $scopeRecords->where('status','Present')->count();
                $late = $scopeRecords->where('status','Late')->count();
                $absent = $scopeRecords->where('status','Absent')->count();
                $incomplete = $scopeRecords->filter(fn($record) => !$record->time_in || !$record->time_out)->count();
                $forReview = $scopeRecords->where('gps_verification_status','For Review')->count();
            @endphp
            <div class="attendance-summary attendance-overview">
                <div><small>TOTAL ACTIVE EMPLOYEES</small><b>{{ $totalActive }}</b></div>
                <div><small>PRESENT</small><b>{{ $present }}</b></div>
                <div><small>LATE</small><b>{{ $late }}</b></div>
                <div><small>ABSENT</small><b>{{ $absent }}</b></div>
                <div><small>INCOMPLETE</small><b>{{ $incomplete }}</b></div>
                <div><small>FOR REVIEW</small><b>{{ $forReview }}</b></div>
            </div>
        @endif

        <div class="table-wrap">
            <table>
                <thead><tr>@if(!auth()->user()->isEmployee())<th>EMPLOYEE</th>@endif<th>DATE</th><th>TIME IN</th><th>TIME OUT</th><th>HOURS</th><th>LATE</th><th>UNDERTIME</th><th>WORKPLACE</th><th>GPS</th><th>STATUS</th>@if(auth()->user()->isAdministrator())<th>ACTION</th>@endif</tr></thead>
                <tbody>
                @forelse($records as $record)
                    <tr>
                        @if(!auth()->user()->isEmployee())
                            <td><a class="employee-cell" href="{{ route('employees.show', $record->employee) }}"><span class="avatar avatar-small">{{ strtoupper(substr($record->employee->first_name,0,1)) }}</span><span><b>{{ $record->employee->full_name }}</b><small>{{ $record->employee->employee_no }} · {{ $record->employee->department?->name ?? 'Department not assigned' }}</small></span></a></td>
                        @endif
                        <td>{{ $record->work_date->format('M j, Y') }}</td>
                        <td>{{ $record->time_in ? substr($record->time_in,0,5) : '—' }}</td>
                        <td>{{ $record->time_out ? substr($record->time_out,0,5) : '—' }}</td>
                        <td>{{ $record->total_working_hours ?? '—' }}</td>
                        <td>{{ $record->late_minutes }} min</td>
                        <td>{{ $record->undertime_minutes }} min</td>
                        <td>{{ $record->timeInWorkplace?->name ?? $record->timeOutWorkplace?->name ?? '—' }}</td>
                        <td><span class="status-tag">{{ $record->gps_verification_status ?? 'Not GPS verified' }}</span></td>
                        <td><span class="status-tag status-{{ \Illuminate\Support\Str::slug($record->status) }}">{{ $record->display_status }}</span>@if($record->calculation_warning)<small class="field-error">{{ $record->calculation_warning }}</small>@endif</td>
                        @if(auth()->user()->isAdministrator())
                            <td>
                                <details class="correction-details">
                                    <summary>Location / correct</summary>
                                    <div class="gps-location-details">
                                        <small>Time-in</small><b>{{ $record->time_in_latitude ? number_format($record->time_in_latitude,7) : '—' }}, {{ $record->time_in_longitude ? number_format($record->time_in_longitude,7) : '—' }}</b>
                                        <small>Time-out</small><b>{{ $record->time_out_latitude ? number_format($record->time_out_latitude,7) : '—' }}, {{ $record->time_out_longitude ? number_format($record->time_out_longitude,7) : '—' }}</b>
                                        <small>Accuracy</small><b>{{ $record->time_in_accuracy_meters ? number_format($record->time_in_accuracy_meters,1).' m (in)' : '—' }}{{ $record->time_out_accuracy_meters ? ' · '.number_format($record->time_out_accuracy_meters,1).' m (out)' : '' }}</b>
                                        <small>Distance</small><b>{{ $record->time_in_distance_meters ? number_format($record->time_in_distance_meters,1).' m (in)' : '—' }}{{ $record->time_out_distance_meters ? ' · '.number_format($record->time_out_distance_meters,1).' m (out)' : '' }}</b>
                                    </div>
                                    <form method="POST" action="{{ route('attendance.correct', $record) }}" class="correction-form" data-confirm-type="form" data-confirm-title="Save Attendance Correction?" data-confirm-message="Save the corrected attendance status, times, and remarks for this employee?" data-confirm-text="Save Correction">
                                        @csrf
                                        <label>Attendance status<select name="status">@foreach(['Present','Late','Absent','On Leave','On Approved Leave','Official Business','Half Day','Rest Day','Holiday'] as $status)<option @selected($record->status === $status)>{{ $status }}</option>@endforeach</select></label>
                                        <label>Time in<input type="time" name="time_in" value="{{ $record->time_in ? substr($record->time_in,0,5) : '' }}"></label>
                                        <label>Time out<input type="time" name="time_out" value="{{ $record->time_out ? substr($record->time_out,0,5) : '' }}"></label>
                                        <label>Late minutes<input type="number" min="0" name="late_minutes" value="{{ $record->late_minutes }}" required></label>
                                        <label>Undertime minutes<input type="number" min="0" name="undertime_minutes" value="{{ $record->undertime_minutes }}" required></label>
                                        <label>Correction remarks *<textarea name="correction_remarks" required rows="2"></textarea></label>
                                        <button class="button button-primary" data-loading-text="Saving…">Save correction</button>
                                    </form>
                                </details>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="11" class="empty-note">No attendance records match the selected filters.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="pagination-wrap">{{ $records->links() }}</div>
    </section>

    @if(auth()->user()->isAdministrator())
        <div class="content-grid">
            <section class="panel panel-wide">
                <div class="panel-heading"><div><h2>Authorized workplaces</h2><p>Configure geofenced attendance locations and accuracy limits.</p></div></div>
                <form method="POST" action="{{ route('attendance.workplaces.store') }}" class="workplace-form top-space" data-confirm-type="form" data-confirm-title="Add Authorized Workplace?" data-confirm-message="Add this geofenced workplace as an authorized attendance location?" data-confirm-text="Add Workplace">
                    @csrf
                    <div class="workplace-picker" x-data="workplaceMapPicker({ searchUrl: @js(route('attendance.workplaces.geocode.search')), reverseUrl: @js(route('attendance.workplaces.geocode.reverse')), latitude: '', longitude: '', radius: 100 })">
                        <div class="workplace-map-tools">
                            <label class="workplace-search-label">Search for a workplace or address
                                <span class="workplace-search-row">
                                    <input type="search" x-model="searchTerm" @keydown.enter.prevent="search()" placeholder="Building, street, barangay, or municipality">
                                    <button type="button" class="button button-outline" @click="search()" :disabled="busy">Search</button>
                                </span>
                            </label>
                            <button type="button" class="button button-outline" @click="useCurrentLocation()" :disabled="busy">Use my current location</button>
                        </div>
                        <div class="workplace-search-results" x-show="searchResults.length" x-cloak>
                            <template x-for="(result, index) in searchResults" :key="`${result.latitude}-${result.longitude}-${index}`">
                                <button type="button" @click="selectResult(result)" x-text="result.display_name"></button>
                            </template>
                        </div>
                        <p class="workplace-map-message" x-show="message" x-text="message" role="status" x-cloak></p>
                        <div class="workplace-map" x-ref="map" role="application" aria-label="Select workplace location on OpenStreetMap"></div>
                        <p class="workplace-map-attribution">Click or drag the marker to refine the location. Map data © OpenStreetMap contributors.</p>
                        <div class="workplace-config-fields">
                            <label>Workplace name *<input name="name" maxlength="150" required></label>
                            <label>Address<input name="address" maxlength="255" x-model="address"></label>
                            <label>Latitude *<input type="number" step="0.0000001" name="latitude" min="-90" max="90" x-model="latitude" readonly required></label>
                            <label>Longitude *<input type="number" step="0.0000001" name="longitude" min="-180" max="180" x-model="longitude" readonly required></label>
                            <label>Allowed radius (meters) *<input type="number" name="radius_meters" min="10" max="10000" value="100" x-model.number="radius" @input="updateRadius()" required></label>
                            <label>Required accuracy (meters) *<input type="number" name="required_accuracy_meters" min="5" max="1000" value="50" required></label>
                        </div>
                    </div>
                    <input type="hidden" name="is_active" value="1">
                    <button class="button button-primary" data-loading-text="Adding…">Add workplace</button>
                </form>
                <div class="table-wrap top-space">
                    <table><thead><tr><th>WORKPLACE</th><th>COORDINATES</th><th>RADIUS</th><th>ACCURACY</th><th>STATUS</th><th>HISTORY</th></tr></thead><tbody>
                    @forelse($workplaces as $workplace)
                        <tr>
                            <td><b>{{ $workplace->name }}</b><small>{{ $workplace->address }}</small></td>
                            <td>{{ number_format($workplace->latitude,7) }}, {{ number_format($workplace->longitude,7) }}</td>
                            <td>{{ $workplace->radius_meters }} m</td>
                            <td>{{ $workplace->required_accuracy_meters }} m</td>
                            <td>{{ $workplace->is_active ? 'Active' : 'Inactive' }}</td>
                            <td>
                                <details class="workplace-edit-details">
                                    <summary>{{ $workplace->history()->count() }} change(s) · Edit</summary>
                                    <form method="POST" action="{{ route('attendance.workplaces.update', $workplace) }}" class="workplace-form workplace-edit-form" data-confirm-type="form" data-confirm-title="Save Workplace Changes?" data-confirm-message="Save the location, geofence, or accuracy changes for {{ $workplace->name }}?" data-confirm-text="Save Workplace">
                                        @csrf
                                        @method('PUT')
                                        <div class="workplace-picker" x-data="workplaceMapPicker({ searchUrl: @js(route('attendance.workplaces.geocode.search')), reverseUrl: @js(route('attendance.workplaces.geocode.reverse')), latitude: @js((string) $workplace->latitude), longitude: @js((string) $workplace->longitude), address: @js($workplace->address), radius: @js($workplace->radius_meters) })">
                                            <div class="workplace-map-tools">
                                                <label class="workplace-search-label">Search for a workplace or address
                                                    <span class="workplace-search-row">
                                                        <input type="search" x-model="searchTerm" @keydown.enter.prevent="search()" placeholder="Building, street, barangay, or municipality">
                                                        <button type="button" class="button button-outline" @click="search()" :disabled="busy">Search</button>
                                                    </span>
                                                </label>
                                                <button type="button" class="button button-outline" @click="useCurrentLocation()" :disabled="busy">Use my current location</button>
                                            </div>
                                            <div class="workplace-search-results" x-show="searchResults.length" x-cloak>
                                                <template x-for="(result, index) in searchResults" :key="`${result.latitude}-${result.longitude}-${index}`">
                                                    <button type="button" @click="selectResult(result)" x-text="result.display_name"></button>
                                                </template>
                                            </div>
                                            <p class="workplace-map-message" x-show="message" x-text="message" role="status" x-cloak></p>
                                            <div class="workplace-map" x-ref="map" role="application" aria-label="Select workplace location on OpenStreetMap"></div>
                                            <p class="workplace-map-attribution">Click or drag the marker to refine the location. Map data © OpenStreetMap contributors.</p>
                                            <div class="workplace-config-fields">
                                                <label>Workplace name *<input name="name" value="{{ $workplace->name }}" maxlength="150" required></label>
                                                <label>Address<input name="address" maxlength="255" x-model="address"></label>
                                                <label>Latitude *<input type="number" step="0.0000001" name="latitude" min="-90" max="90" x-model="latitude" readonly required></label>
                                                <label>Longitude *<input type="number" step="0.0000001" name="longitude" min="-180" max="180" x-model="longitude" readonly required></label>
                                                <label>Radius (meters) *<input type="number" name="radius_meters" min="10" max="10000" x-model.number="radius" @input="updateRadius()" required></label>
                                                <label>Required accuracy (meters) *<input type="number" name="required_accuracy_meters" min="5" max="1000" value="{{ $workplace->required_accuracy_meters }}" required></label>
                                                <label>Status<select name="is_active"><option value="1" @selected($workplace->is_active)>Active</option><option value="0" @selected(!$workplace->is_active)>Inactive</option></select></label>
                                            </div>
                                        </div>
                                        <button class="button button-outline">Update workplace</button>
                                    </form>
                                </details>
                            </td>
                        </tr>
                    @empty<tr><td colspan="6" class="empty-note">No authorized workplaces configured.</td></tr>@endforelse
                    </tbody></table>
                </div>
            </section>

            <section class="panel panel-wide workplace-assignment-panel">
                <div class="panel-heading">
                    <div><h2>Employee workplace assignments</h2><p>Effective workplace priority: individual assignment, department default, then unassigned.</p></div>
                </div>
                <div
                    class="workplace-assignment-tabs"
                    x-data="workplaceAssignments({
                        activeTab: @js(in_array(request('assignment_tab'), ['department', 'bulk', 'individual'], true) ? request('assignment_tab') : 'department'),
                        departmentEmployees: @js($departmentAssignmentPreview),
                        departmentPreviewUrl: @js(route('attendance.workplaces.department-preview')),
                        employees: @js($employeeAssignmentLookup),
                        visibleEmployeeIds: @js($assignmentEmployees->pluck('id')->map(fn ($id) => (string) $id)->all()),
                        selectedDepartment: @js(old('department_id')),
                        selectedEffectiveDate: @js(old('effective_from', today()->toDateString())),
                        selectedEmployee: @js(request('selected_employee')),
                        individualAssignmentUrl: @js(route('attendance.employees.workplace', ['employee' => '__EMPLOYEE__']))
                    })"
                >
                    <div class="workplace-tab-list" role="tablist" aria-label="Workplace assignment methods">
                        <button type="button" role="tab" :aria-selected="activeTab === 'department'" :class="{ 'is-active': activeTab === 'department' }" @click="activeTab = 'department'">Department Assignment</button>
                        <button type="button" role="tab" :aria-selected="activeTab === 'bulk'" :class="{ 'is-active': activeTab === 'bulk' }" @click="activeTab = 'bulk'">Bulk Employee Assignment</button>
                        <button type="button" role="tab" :aria-selected="activeTab === 'individual'" :class="{ 'is-active': activeTab === 'individual' }" @click="activeTab = 'individual'">Individual Assignment</button>
                    </div>

                    <div class="workplace-tab-content" x-show="activeTab === 'department'" x-cloak>
                        <form method="POST" action="{{ route('attendance.workplaces.assign-department') }}" class="form-stack" @submit.prevent="confirmDepartmentAssignment($event)">
                            @csrf
                            <input type="hidden" name="assignment_tab" value="department">
                            <div class="workplace-assignment-fields">
                                <label>Department *
                                    <select name="department_id" x-model="selectedDepartment" @change="refreshDepartmentRoster()" required>
                                        <option value="">Select department</option>
                                        @foreach($departments as $department)
                                            <option value="{{ $department->id }}">{{ $department->name }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label>Authorized workplace *
                                    <select name="attendance_workplace_id" required>
                                        <option value="">Select active workplace</option>
                                        @foreach($workplaces->where('is_active', true) as $workplace)
                                            <option value="{{ $workplace->id }}">{{ $workplace->name }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label>Effective date *
                                    <input type="date" name="effective_from" x-model="selectedEffectiveDate" @change="refreshDepartmentRoster()" required>
                                </label>
                            </div>
                            <label>Reason for assignment *
                                <textarea name="reason" rows="2" maxlength="2000" required>{{ old('reason') }}</textarea>
                            </label>
                            <div class="assignment-review" x-show="selectedDepartment" x-cloak>
                                <b>Review affected employees (<span x-text="departmentRoster().length"></span>)</b>
                                <p>Individual assignments remain in effect unless you explicitly select the confirmation below.</p>
                                <p x-show="previewBusy" role="status">Refreshing employee assignments for the selected effective date…</p>
                                <p x-show="previewError" class="field-error" x-text="previewError" role="alert"></p>
                                <div class="assignment-review-list">
                                    <template x-for="employee in departmentRoster()" :key="employee.employee_no">
                                        <div><span x-text="`${employee.employee_no} · ${employee.name}`"></span><small x-text="`${employee.workplace ?? 'No effective workplace'}${['individual', 'bulk'].includes(employee.assignment_type) ? ' · Individual override' : ''}`"></small></div>
                                    </template>
                                </div>
                            </div>
                            <label class="checkbox-label" x-show="individualOverrideCount() > 0" x-cloak>
                                <input type="checkbox" name="replace_individual_assignments" value="1">
                                <span><b>Replace <span x-text="individualOverrideCount()"></span> individual assignment(s)</b><small>This explicitly ends their individual precedence on the selected effective date and lets the department default apply.</small></span>
                            </label>
                            <button class="button button-primary" type="submit" :disabled="previewBusy || !!previewError">Review and assign department workplace</button>
                        </form>
                    </div>

                    <div class="workplace-tab-content" x-show="activeTab === 'bulk'" x-cloak>
                        <form method="GET" action="{{ route('attendance.index') }}" class="assignment-filter-form">
                            <input type="hidden" name="assignment_tab" value="bulk">
                            <label>Search employee
                                <input type="search" name="assignment_search" value="{{ request('assignment_search') }}" placeholder="Name or employee ID">
                            </label>
                            <label>Department
                                <select name="assignment_department">
                                    <option value="">All departments</option>
                                    @foreach($departments as $department)
                                        <option value="{{ $department->id }}" @selected((string) request('assignment_department') === (string) $department->id)>{{ $department->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label>Workplace
                                <select name="assignment_workplace">
                                    <option value="">All workplaces</option>
                                    <option value="unassigned" @selected(request('assignment_workplace') === 'unassigned')>No effective workplace</option>
                                    @foreach($workplaces->where('is_active', true) as $workplace)
                                        <option value="{{ $workplace->id }}" @selected((string) request('assignment_workplace') === (string) $workplace->id)>{{ $workplace->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label>Assignment status
                                <select name="assignment_status">
                                    <option value="">All statuses</option>
                                    <option value="assigned" @selected(request('assignment_status') === 'assigned')>Assigned</option>
                                    <option value="unassigned" @selected(request('assignment_status') === 'unassigned')>Unassigned</option>
                                </select>
                            </label>
                            <button class="button button-outline" type="submit">Apply filters</button>
                        </form>

                        <form method="POST" action="{{ route('attendance.workplaces.assign-bulk') }}" class="form-stack workplace-bulk-form" @submit.prevent="confirmBulkAssignment($event)">
                            @csrf
                            <input type="hidden" name="assignment_tab" value="bulk">
                            <div class="workplace-assignment-fields">
                                <label>Assign selected employees to *
                                    <select name="attendance_workplace_id" required>
                                        <option value="">Select active workplace</option>
                                        @foreach($workplaces->where('is_active', true) as $workplace)
                                            <option value="{{ $workplace->id }}">{{ $workplace->name }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label>Effective date *
                                    <input type="date" name="effective_from" value="{{ old('effective_from', today()->toDateString()) }}" required>
                                </label>
                                <label>Reason for assignment *
                                    <input name="reason" maxlength="2000" value="{{ old('reason') }}" required>
                                </label>
                            </div>
                            <div class="table-wrap top-space">
                                <table>
                                    <thead><tr>
                                        <th><label class="sr-only" for="select-visible-employees">Select all employees on this page</label><input id="select-visible-employees" type="checkbox" @change="selectVisibleEmployees($event)" aria-label="Select all employees on this page"></th>
                                        <th>EMPLOYEE NAME</th><th>EMPLOYEE ID</th><th>DEPARTMENT</th><th>CURRENT WORKPLACE</th><th>ASSIGNMENT TYPE</th><th>EFFECTIVE DATE</th><th>ACTION</th>
                                    </tr></thead>
                                    <tbody>
                                    @forelse($assignmentEmployees as $employee)
                                        @php($effectiveAssignment = $effectiveWorkplaceAssignments[$employee->id] ?? ['workplace' => null, 'assignment_type' => null, 'effective_from' => null])
                                        <tr>
                                            <td><input type="checkbox" name="employee_ids[]" value="{{ $employee->id }}" x-model="selectedEmployeeIds" aria-label="Select {{ $employee->full_name }}"></td>
                                            <td>{{ $employee->full_name }}</td>
                                            <td>{{ $employee->employee_no }}</td>
                                            <td>{{ $employee->department?->name ?? '—' }}</td>
                                            <td>{{ $effectiveAssignment['workplace']?->name ?? 'No assignment' }}</td>
                                            <td>{{ $effectiveAssignment['assignment_type'] ? ucfirst($effectiveAssignment['assignment_type']) : '—' }}</td>
                                            <td>{{ $effectiveAssignment['effective_from']?->format('M j, Y') ?? '—' }}</td>
                                            <td><a class="button button-outline button-small" href="{{ route('attendance.index', array_merge(request()->query(), ['assignment_tab' => 'individual', 'selected_employee' => $employee->id])) }}">Individual</a></td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="8" class="empty-note">No employees match these filters.</td></tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                            <p class="form-hint"><span x-text="selectedEmployeeIds.length"></span> employee(s) selected on this page.</p>
                            <button class="button button-primary" type="submit">Confirm bulk assignment</button>
                            <div class="pagination-wrap">{{ $assignmentEmployees->links() }}</div>
                        </form>
                    </div>

                    <div class="workplace-tab-content" x-show="activeTab === 'individual'" x-cloak>
                        <form method="POST" x-bind:action="individualAssignmentUrl.replace('__EMPLOYEE__', selectedEmployee)" class="form-stack" data-confirm-type="form" data-confirm-title="Save Individual Workplace Assignment?" data-confirm-message="Save or clear the selected employee’s individual workplace assignment from the effective date?" data-confirm-text="Save Assignment">
                            @csrf
                            <label>Search employee
                                <input type="search" x-model="employeeSearch" placeholder="Search by name or employee ID">
                            </label>
                            <label>Employee *
                                <select name="employee_id" x-model="selectedEmployee" required>
                                    <option value="">Select employee</option>
                                    <template x-for="employee in filteredEmployees()" :key="employee.id">
                                        <option :value="employee.id" x-text="`${employee.employee_no} · ${employee.name}`"></option>
                                    </template>
                                </select>
                            </label>
                            <div class="assignment-current" x-show="selectedEmployeeDetails()" x-cloak>
                                <b>Current effective workplace</b>
                                <span x-text="selectedEmployeeDetails()?.workplace ?? 'No assignment'"></span>
                                <small x-text="selectedEmployeeDetails()?.assignment_type ? `${selectedEmployeeDetails().assignment_type} assignment` : 'No active employee or department assignment'"></small>
                            </div>
                            <div class="workplace-assignment-fields">
                                <label>Individual workplace
                                    <select name="attendance_workplace_id">
                                        <option value="">Use department default (clear individual override)</option>
                                        @foreach($workplaces->where('is_active', true) as $workplace)
                                            <option value="{{ $workplace->id }}">{{ $workplace->name }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label>Effective date *
                                    <input type="date" name="effective_from" value="{{ old('effective_from', today()->toDateString()) }}" required>
                                </label>
                            </div>
                            <label>Reason for reassignment *
                                <textarea name="reason" rows="2" maxlength="2000" required>{{ old('reason') }}</textarea>
                            </label>
                            <button class="button button-primary" type="submit" :disabled="!selectedEmployee" data-loading-text="Saving…">Save individual assignment</button>
                        </form>
                    </div>
                </div>
            </section>

            <section class="panel panel-wide">
                <div class="panel-heading"><div><h2>Workplace assignment history</h2><p>Assignment events are retained and never removed when a workplace changes.</p></div></div>
                <div class="table-wrap top-space">
                    <table>
                        <thead><tr><th>EMPLOYEE</th><th>DEPARTMENT</th><th>PREVIOUS WORKPLACE</th><th>NEW WORKPLACE</th><th>TYPE</th><th>EFFECTIVE DATE</th><th>ASSIGNED BY</th><th>REASON</th></tr></thead>
                        <tbody>
                        @forelse($assignmentHistory as $history)
                            <tr>
                                <td>{{ $history->employee?->employee_no ? $history->employee->employee_no.' · '.$history->employee->full_name : 'Department assignment' }}</td>
                                <td>{{ $history->department?->name ?? '—' }}</td>
                                <td>{{ $history->previousWorkplace?->name ?? '—' }}</td>
                                <td>{{ $history->newWorkplace?->name ?? 'Department default / cleared' }}</td>
                                <td>{{ ucfirst($history->assignment_type) }}</td>
                                <td>{{ $history->effective_date->format('M j, Y') }}</td>
                                <td>{{ $history->assignedBy?->name ?? '—' }}<small>{{ $history->created_at->format('M j, Y g:i A') }}</small></td>
                                <td>{{ $history->reason }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="empty-note">No workplace assignment history.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="pagination-wrap">{{ $assignmentHistory->links() }}</div>
            </section>

            <section class="panel panel-wide">
                <div class="panel-heading"><div><h2>Attendance exceptions</h2><p>Approve or reject requests without creating an attendance record automatically.</p></div></div>
                <div class="table-wrap top-space">
                    <table><thead><tr><th>EMPLOYEE</th><th>DATE</th><th>ACTION</th><th>EXPLANATION</th><th>STATUS</th><th>REVIEW</th></tr></thead><tbody>
                    @forelse($attendanceExceptions as $exception)
                        <tr><td>{{ $exception->employee->full_name }}</td><td>{{ $exception->attendance_date->format('M j, Y') }}</td><td>{{ str_replace('_',' ',ucfirst($exception->requested_action)) }}</td><td>{{ $exception->explanation }}</td><td>{{ $exception->status }}</td><td>@if($exception->status === 'Pending')<form method="POST" action="{{ route('attendance.exceptions.review', $exception) }}" class="form-stack" data-confirm-type="approval" data-confirm-title="Review Attendance Exception?" data-confirm-message="Confirm this attendance exception decision." data-confirm-text="Save Decision">@csrf<select name="status" data-confirm-status><option>Approved</option><option>Rejected</option></select><textarea name="review_remarks" rows="2" maxlength="2000"></textarea><button class="button button-outline" data-loading-text="Saving…">Save review</button></form>@else{{ $exception->reviewer?->name }} · {{ $exception->reviewed_at?->format('M j, Y') }}@endif</td></tr>
                    @empty<tr><td colspan="6" class="empty-note">No attendance exception requests.</td></tr>@endforelse
                    </tbody></table>
                </div>
            </section>

            <section class="panel panel-wide">
                <div class="panel-heading"><div><h2>Work schedules</h2><p>Schedule changes take effect only on their approved dates. Existing attendance calculations remain unchanged.</p></div></div>
                <div class="info-note"><b>Default schedule:</b> Monday–Friday, 8:00 AM–5:00 PM; lunch 12:00–1:00 PM; zero-minute grace.</div>
                <form method="POST" action="{{ route('attendance.schedules.store') }}" class="form-stack top-space" data-confirm-type="form" data-confirm-title="Save Approved Work Schedule?" data-confirm-message="Save this work schedule and its effective period for the selected employee or office?" data-confirm-text="Save Schedule">@csrf
                    <label>Schedule scope<select name="employee_id"><option value="">Office-wide default</option>@foreach($employees as $employee)<option value="{{ $employee->id }}">{{ $employee->employee_no }} · {{ $employee->full_name }}</option>@endforeach</select></label>
                    <div class="weekday-grid">@foreach([1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday',7=>'Sunday'] as $day=>$name)<label class="choice-card"><input type="checkbox" name="working_days[]" value="{{ $day }}" @checked(in_array($day, old('working_days', $defaultSchedule['working_days']), false))><span><b>{{ $name }}</b></span></label>@endforeach</div>
                    <div class="form-grid"><label>Start time *<input type="time" name="start_time" value="{{ old('start_time','08:00') }}" required></label><label>End time *<input type="time" name="end_time" value="{{ old('end_time','17:00') }}" required></label><label>Lunch starts<input type="time" name="lunch_start" value="{{ old('lunch_start','12:00') }}"></label><label>Lunch ends<input type="time" name="lunch_end" value="{{ old('lunch_end','13:00') }}"></label><label>Grace period (minutes) *<input type="number" name="grace_period_minutes" min="0" max="240" value="{{ old('grace_period_minutes',0) }}" required></label><label>Effective from *<input type="date" name="effective_from" value="{{ old('effective_from',today()->toDateString()) }}" required></label><label>Effective to<input type="date" name="effective_to"></label><label class="field-span">Approved reason *<textarea name="reason" rows="2" maxlength="2000" required></textarea></label></div>
                    <button class="button button-primary" type="submit" data-loading-text="Saving…">Save approved schedule</button>
                </form>
                <div class="table-wrap top-space"><table><thead><tr><th>SCOPE</th><th>WORKING DAYS</th><th>HOURS</th><th>EFFECTIVE PERIOD</th><th>APPROVED REASON</th><th></th></tr></thead><tbody>
                    @forelse($workSchedules as $schedule)<tr><td>{{ $schedule->employee ? $schedule->employee->employee_no.' · '.$schedule->employee->full_name : 'Office-wide default' }}</td><td>{{ collect($schedule->working_days)->map(fn($day)=>[1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',7=>'Sun'][$day]??'')->implode(', ') }}</td><td>{{ substr($schedule->start_time,0,5) }}–{{ substr($schedule->end_time,0,5) }} · {{ $schedule->grace_period_minutes }}m grace</td><td>{{ $schedule->effective_from->format('M j, Y') }} – {{ $schedule->effective_to?->format('M j, Y') ?? 'Current' }}</td><td>{{ $schedule->reason }}<small>Recorded by {{ $schedule->creator->name }}</small></td><td>@if(!$schedule->effective_to)<form method="POST" action="{{ route('attendance.schedules.end',$schedule) }}" class="form-stack" data-confirm-type="warning" data-confirm-title="End Work Schedule?" data-confirm-message="Set an end date for this approved work schedule? The recorded schedule history will be retained." data-confirm-text="End Schedule">@csrf @method('PATCH')<label>End date<input type="date" name="effective_to" min="{{ $schedule->effective_from->toDateString() }}" required></label><button class="button button-outline" data-loading-text="Saving…">End schedule</button></form>@endif</td></tr>@empty<tr><td colspan="6" class="empty-note">No explicit schedule records.</td></tr>@endforelse
                </tbody></table></div><div class="pagination-wrap">{{ $workSchedules->links() }}</div>
            </section>
        </div>
    @endif
@endsection
