@props([
    'inputId',
    'name' => 'employee_id',
    'label' => 'Employee',
    'placeholder' => 'Search by name or employee ID',
    'searchUrl' => route('employees.search'),
    'selected' => null,
    'filters' => [],
    'required' => false,
    'disabledExpression' => null,
    'includeSelected' => false,
    'targetUserId' => null,
])
@php
    $selectedOption = is_array($selected)
        ? $selected
        : ($selected ? [
            'id' => $selected->id,
            'employee_no' => $selected->employee_no,
            'name' => $selected->full_name,
            'label' => $selected->employee_no.' · '.$selected->full_name,
        ] : null);
@endphp
<div
    class="employee-picker"
    x-data="employeePicker({
        searchUrl: @js($searchUrl),
        inputId: @js($inputId),
        filters: @js($filters),
        selected: @js($selectedOption),
        required: @js($required),
        includeEmployeeId: @js($includeSelected ? ($selectedOption['id'] ?? null) : null),
        targetUserId: @js($targetUserId)
    })"
    @click.outside="isOpen = false"
    @submit.window.capture="if ($event.target instanceof HTMLFormElement && $event.target.contains($el)) validateBeforeSubmit($event)"
>
    <label for="{{ $inputId }}">{{ $label }}@if($required) * @endif</label>
    <div class="employee-picker-input-wrap">
        <input
            id="{{ $inputId }}"
            x-ref="search"
            type="search"
            x-model="searchTerm"
            @focus="open()"
            @input="handleInput()"
            @keydown.arrow-down.prevent="moveActive(1)"
            @keydown.arrow-up.prevent="moveActive(-1)"
            @keydown.enter="handleEnter($event)"
            @keydown.escape.stop="isOpen = false"
            role="combobox"
            aria-autocomplete="list"
            aria-controls="{{ $inputId }}-listbox"
            :aria-expanded="isOpen.toString()"
            :aria-activedescendant="activeOptionId() ?? ''"
            autocomplete="off"
            placeholder="{{ $placeholder }}"
            @if($required) required @endif
            @if($disabledExpression) :disabled="{{ $disabledExpression }}" @endif
        >
        <input
            type="hidden"
            name="{{ $name }}"
            :value="selected?.id ?? ''"
            @if($disabledExpression) :disabled="{{ $disabledExpression }}" @endif
        >
        <button
            type="button"
            class="employee-picker-clear"
            x-show="selected"
            x-cloak
            @click="clearSelection()"
            aria-label="Clear selected employee"
        >&times;</button>
    </div>
    <div class="employee-picker-menu" x-show="isOpen" x-cloak>
        <ul id="{{ $inputId }}-listbox" role="listbox" :aria-busy="loading.toString()">
            <template x-for="(employee, index) in results" :key="employee.id">
                <li role="presentation">
                    <button
                        type="button"
                        role="option"
                        :id="optionId(employee)"
                        :aria-selected="(selected?.id === employee.id).toString()"
                        :class="{ 'is-active': activeIndex === index }"
                        @mousedown.prevent
                        @click="choose(employee)"
                    >
                        <span x-text="employee.label"></span>
                        <small x-show="employee.position || employee.department" x-text="[employee.position, employee.department].filter(Boolean).join(' · ')"></small>
                    </button>
                </li>
            </template>
            <li class="employee-picker-state" x-show="loading" role="status">Searching employees…</li>
            <li class="employee-picker-state employee-picker-error" x-show="error" x-text="error" role="alert"></li>
            <li class="employee-picker-state" x-show="hasLoaded && !loading && !error && !results.length">No employees found.</li>
        </ul>
        <button
            type="button"
            class="employee-picker-more"
            x-show="hasMore && !loading"
            @click="loadMore()"
        >Load more</button>
    </div>
</div>
