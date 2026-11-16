<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_credit_transactions', function (Blueprint $table) {
            $table->decimal('previous_balance', 8, 2)->nullable()->after('amount');
            $table->date('accrual_month')->nullable()->after('effective_date');
            $table->unique(['leave_credit_id', 'accrual_month'], 'leave_credit_monthly_accrual_unique');
        });

        Schema::table('leave_applications', function (Blueprint $table) {
            $table->json('policy_details')->nullable()->after('additional_details');
            $table->timestamp('eligibility_verified_at')->nullable()->after('certified_at');
            $table->foreignId('eligibility_verified_by')->nullable()->after('eligibility_verified_at')->constrained('users')->nullOnDelete();
            $table->text('eligibility_remarks')->nullable()->after('eligibility_verified_by');
        });

        DB::table('leave_credit_transactions')->whereNull('previous_balance')->update([
            'previous_balance' => DB::raw("CASE WHEN type = 'used' THEN balance_after + amount ELSE balance_after - amount END"),
        ]);

        if (Schema::hasTable('leave_types')) {
            $defaults = [
                'VACATION' => [
                    'annual_entitlement_days' => 15,
                    'monthly_accrual' => 1.25,
                    'accrual_eligibility' => [
                        'employment_statuses' => ['Active'],
                        'employment_types' => [],
                        'require_date_hired' => true,
                        'accrue_after_full_month' => true,
                    ],
                    'consumes_regular_credit' => true,
                    'filing_advance_days' => 5,
                ],
                'SICK' => [
                    'annual_entitlement_days' => 15,
                    'monthly_accrual' => 1.25,
                    'accrual_eligibility' => [
                        'employment_statuses' => ['Active'],
                        'employment_types' => [],
                        'require_date_hired' => true,
                        'accrue_after_full_month' => true,
                    ],
                    'consumes_regular_credit' => true,
                    'conditional_required_documents' => [
                        ['document' => 'Medical certificate when required', 'condition' => 'minimum_working_days', 'value' => 5],
                        ['document' => 'Affidavit or HRMO-approved documentation if no physician attended', 'condition' => 'policy_detail_equals', 'field' => 'medical_attention', 'value' => 'not_attended'],
                    ],
                    'application_fields' => [
                        ['key' => 'medical_attention', 'label' => 'Were you attended by a physician?', 'type' => 'select', 'required' => false, 'options' => [
                            ['value' => 'attended', 'label' => 'Yes'],
                            ['value' => 'not_attended', 'label' => 'No'],
                        ]],
                    ],
                ],
                'FORCED' => ['annual_entitlement_days' => 5, 'entitlement_period' => 'annual', 'credit_source' => 'FORCED', 'forfeit_unused_at_year_end' => true],
                'MATERNITY' => [
                    'entitlement_days' => 105,
                    'entitlement_unit' => 'calendar_days',
                    'eligibility_review_required' => true,
                    'application_fields' => [
                        ['key' => 'expected_delivery_date', 'label' => 'Expected date of delivery', 'type' => 'date', 'required' => true],
                        ['key' => 'maternity_allocation_applies', 'label' => 'Notice of Allocation applies?', 'type' => 'select', 'required' => false, 'options' => [['value' => 'yes', 'label' => 'Yes'], ['value' => 'no', 'label' => 'No']]],
                        ['key' => 'second_female_employee', 'label' => 'Is this the second female employee in the recipient agency?', 'type' => 'select', 'required' => true, 'options' => [['value' => 'yes', 'label' => 'Yes'], ['value' => 'no', 'label' => 'No']]],
                    ],
                    'conditional_required_documents' => [
                        ['document' => 'Notice of Allocation of Maternity Leave Credits, when applicable', 'condition' => 'policy_detail_equals', 'field' => 'maternity_allocation_applies', 'value' => 'yes'],
                    ],
                ],
                'PATERNITY' => [
                    'entitlement_days' => 7,
                    'entitlement_unit' => 'calendar_days',
                    'eligibility_review_required' => true,
                    'application_fields' => [
                        ['key' => 'delivery_date', 'label' => 'Child delivery date', 'type' => 'date', 'required' => true],
                        ['key' => 'paternity_medical_certificate_applies', 'label' => 'Medical certificate applicable?', 'type' => 'select', 'required' => false, 'options' => [['value' => 'yes', 'label' => 'Yes'], ['value' => 'no', 'label' => 'No']]],
                        ['key' => 'paternity_marriage_contract_applies', 'label' => 'Marriage contract applicable?', 'type' => 'select', 'required' => false, 'options' => [['value' => 'yes', 'label' => 'Yes'], ['value' => 'no', 'label' => 'No']]],
                    ],
                    'conditional_required_documents' => [
                        ['document' => 'Medical certificate when applicable', 'condition' => 'policy_detail_equals', 'field' => 'paternity_medical_certificate_applies', 'value' => 'yes'],
                        ['document' => 'Marriage contract when applicable', 'condition' => 'policy_detail_equals', 'field' => 'paternity_marriage_contract_applies', 'value' => 'yes'],
                    ],
                ],
                'SPECIAL_PRIVILEGE' => [
                    'entitlement_days' => 3,
                    'entitlement_period' => 'annual',
                    'filing_advance_days' => 7,
                    'emergency_exception_requires_review' => true,
                    'application_fields' => [
                        ['key' => 'travel_location', 'label' => 'Travel location', 'type' => 'select', 'required' => true, 'options' => [['value' => 'within_philippines', 'label' => 'Within the Philippines'], ['value' => 'abroad', 'label' => 'Abroad']]],
                        ['key' => 'emergency_case', 'label' => 'Emergency exception?', 'type' => 'select', 'required' => false, 'options' => [['value' => 'yes', 'label' => 'Yes'], ['value' => 'no', 'label' => 'No']]],
                    ],
                    'conditional_required_documents' => [
                        ['document' => 'Travel authority and clearance from money and property accountabilities', 'condition' => 'policy_detail_in', 'field' => 'travel_location', 'value' => ['within_philippines', 'abroad']],
                    ],
                ],
                'SOLO_PARENT' => [
                    'entitlement_days' => 7, 'entitlement_period' => 'annual', 'filing_advance_days' => 5, 'eligibility_review_required' => true,
                    'application_fields' => [['key' => 'solo_parent_id_expiry', 'label' => 'Solo Parent ID expiry date', 'type' => 'date', 'required' => true]],
                ],
                'STUDY' => [
                    'max_entitlement_months' => 6, 'eligibility_review_required' => true,
                    'application_fields' => [['key' => 'study_purpose', 'label' => 'Study purpose and program', 'type' => 'textarea', 'required' => true]],
                ],
                'VAWC' => [
                    'entitlement_days' => 10, 'entitlement_period' => 'annual', 'filing_advance_optional' => true, 'eligibility_review_required' => true,
                    'application_fields' => [['key' => 'protection_order_status', 'label' => 'Protection order status', 'type' => 'select', 'required' => true, 'options' => [['value' => 'issued', 'label' => 'Issued'], ['value' => 'pending', 'label' => 'Pending']]]],
                    'conditional_required_documents' => [
                        ['document' => 'Punong Barangay/Kagawad or court certification while protection order is pending', 'condition' => 'policy_detail_equals', 'field' => 'protection_order_status', 'value' => 'pending'],
                        ['document' => 'Barangay Protection Order (BPO), Temporary Protection Order (TPO), or Permanent Protection Order (PPO)', 'condition' => 'policy_detail_equals', 'field' => 'protection_order_status', 'value' => 'issued'],
                    ],
                ],
                'REHABILITATION' => [
                    'max_entitlement_months' => 6, 'eligibility_review_required' => true,
                    'application_fields' => [
                        ['key' => 'incident_date', 'label' => 'Incident or accident date', 'type' => 'date', 'required' => true],
                        ['key' => 'private_attending_physician', 'label' => 'Is the attending physician in private practice?', 'type' => 'select', 'required' => false, 'options' => [['value' => 'yes', 'label' => 'Yes'], ['value' => 'no', 'label' => 'No']]],
                    ],
                    'conditional_required_documents' => [
                        ['document' => 'Written concurrence of a government physician', 'condition' => 'policy_detail_equals', 'field' => 'private_attending_physician', 'value' => 'yes'],
                    ],
                ],
                'WOMEN_SPECIAL' => [
                    'max_entitlement_months' => 2, 'filing_advance_days' => 5, 'emergency_return_filing_allowed' => true,
                    'application_fields' => [
                        ['key' => 'surgery_date', 'label' => 'Scheduled or completed surgery date', 'type' => 'date', 'required' => true],
                        ['key' => 'emergency_case', 'label' => 'Emergency filing?', 'type' => 'select', 'required' => false, 'options' => [['value' => 'yes', 'label' => 'Yes'], ['value' => 'no', 'label' => 'No']]],
                    ],
                ],
                'CALAMITY' => [
                    'entitlement_days' => 5, 'entitlement_period' => 'annual', 'use_within_occurrence_days' => 30, 'maximum_uses_per_year' => 1, 'eligibility_review_required' => true,
                    'application_fields' => [
                        ['key' => 'calamity_occurrence_date', 'label' => 'Calamity occurrence date', 'type' => 'date', 'required' => true],
                        ['key' => 'affected_residence', 'label' => 'Affected residence / area', 'type' => 'text', 'required' => true],
                    ],
                ],
                'ADOPTION' => [
                    'eligibility_review_required' => true,
                    'application_fields' => [['key' => 'placement_authority_date', 'label' => 'Pre-Adoptive Placement Authority date', 'type' => 'date', 'required' => true]],
                ],
                'MONETIZATION' => [
                    'minimum_credit_fraction' => 0.5, 'requires_agency_head_decision' => true, 'not_absence_leave' => true,
                    'application_fields' => [
                        ['key' => 'requested_credit_days', 'label' => 'Leave credit days requested for monetization', 'type' => 'number', 'required' => true],
                        ['key' => 'justifiable_reason', 'label' => 'Valid and justifiable reason', 'type' => 'textarea', 'required' => true],
                    ],
                ],
                'TERMINAL' => [
                    'requires_separation_proof' => true, 'not_absence_leave' => true, 'monetary_payout_calculation' => false,
                    'application_fields' => [['key' => 'separation_date', 'label' => 'Date of separation from service', 'type' => 'date', 'required' => true]],
                ],
                'WELLNESS' => [
                    'entitlement_days' => 5,
                    'entitlement_period' => 'annual',
                    'automatic_grant' => false,
                    'consumes_regular_credit' => false,
                    'eligibility_review_required' => true,
                    'application_fields' => [['key' => 'eligibility_basis', 'label' => 'Eligibility basis / authorized local policy', 'type' => 'textarea', 'required' => true]],
                ],
            ];
            foreach ($defaults as $code => $policy) {
                $type = DB::table('leave_types')->where('code', $code)->first();
                if ($type) {
                    $current = $type->rules ? json_decode($type->rules, true, 512, JSON_THROW_ON_ERROR) : [];
                    $update = ['rules' => json_encode(array_replace_recursive($policy, $current), JSON_THROW_ON_ERROR)];
                    $legacyDocuments = [
                        'VACATION' => ['Travel destination, if applicable'],
                        'SICK' => ['Medical certificate when required', 'Explanation if consultation was unavailable'],
                        'MATERNITY' => ['Ultrasound or doctor certificate', 'Expected date of delivery', 'Notice of Allocation of Maternity Leave Credits, when applicable'],
                        'PATERNITY' => ['Child birth certificate', 'Medical certificate when applicable', 'Marriage contract when required'],
                        'SPECIAL_PRIVILEGE' => ['Travel destination, if applicable'],
                        'SOLO_PARENT' => ['Updated Solo Parent Identification Card'],
                        'STUDY' => ['Agency requirements', 'Agreement with the agency'],
                        'VAWC' => ['Protection order or appropriate barangay/court certification'],
                        'REHABILITATION' => ['Letter request', 'Medical certificate and records', 'Police report when applicable'],
                        'WOMEN_SPECIAL' => ['Medical certificate', 'Clinical summary', 'Relevant report and recovery period'],
                        'CALAMITY' => ['Circumstance and supporting information for verification'],
                        'ADOPTION' => ['Authenticated Pre-Adoptive Placement Authority when required'],
                        'TERMINAL' => ['Resignation, retirement, or separation documents', 'Clearance documentation'],
                        'MONETIZATION' => ['Letter request'],
                    ];
                    $legacyGuidance = [
                        'VACATION' => 'Normally file in advance where possible. If leave will be spent abroad, provide the destination. Verify current filing rules with HR.',
                        'SICK' => 'For advance filing or an absence exceeding five days, HR may require medical documentation under current policy.',
                        'SPECIAL_PRIVILEGE' => 'Normally file at least one week in advance where possible; emergency exceptions require HR review.',
                    ];
                    $guidanceDefaults = [
                        'VACATION' => 'File at least five days in advance whenever possible. Confirm applicable filing requirements with HRMO.',
                        'SICK' => 'File immediately upon return. A medical certificate is required for an absence of five calendar days or more; HRMO will review documentation when a physician was not consulted.',
                        'SPECIAL_PRIVILEGE' => 'File at least one week in advance when possible. For travel within the Philippines or abroad, attach the applicable travel authority and clearance from money and property accountabilities.',
                    ];
                    if (isset($legacyGuidance[$code]) && $type->guidance === $legacyGuidance[$code]) {
                        $update['guidance'] = $guidanceDefaults[$code];
                    }
                    if (isset($legacyDocuments[$code])
                        && json_decode($type->required_documents ?? '[]', true, 512, JSON_THROW_ON_ERROR) === $legacyDocuments[$code]) {
                        $update['required_documents'] = json_encode(match ($code) {
                            'VACATION', 'SPECIAL_PRIVILEGE' => [],
                            'SICK' => [],
                            'MATERNITY' => ['Ultrasound or doctor certificate indicating the expected date of delivery'],
                            'PATERNITY' => ['Child birth certificate'],
                            'SOLO_PARENT' => ['Updated Solo Parent Identification Card'],
                            'STUDY' => ['Agency requirements', 'Agreement with the agency'],
                            'VAWC' => [],
                            'REHABILITATION' => ['Incident or accident report', 'Relevant medical reports', 'Medical certificate describing injury, recuperation, and rehabilitation'],
                            'WOMEN_SPECIAL' => ['Medical certificate', 'Clinical summary'],
                            'CALAMITY' => ['Government agency declaration or certification', 'Proof of residence or affected area'],
                            'ADOPTION' => ['Authentic copy of Pre-Adoptive Placement Authority issued by DSWD'],
                            'TERMINAL' => ['Proof of resignation, retirement, or separation from service'],
                            'MONETIZATION' => ['Letter request stating valid and justifiable reasons'],
                        }, JSON_THROW_ON_ERROR);
                    }
                    DB::table('leave_types')->where('id', $type->id)->update($update);
                } elseif ($code === 'WELLNESS') {
                    DB::table('leave_types')->insert([
                        'name' => 'Wellness Leave',
                        'code' => 'WELLNESS',
                        'guidance' => 'Local five-day Wellness Leave category. HRMO must configure eligibility and determine whether an individual balance is granted or consumed.',
                        'required_documents' => json_encode([]),
                        'rules' => json_encode($policy, JSON_THROW_ON_ERROR),
                        'requires_credit' => false,
                        'is_sensitive' => false,
                        'is_active' => true,
                        'sort_order' => 17,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        if (Schema::hasTable('leave_types') && Schema::hasTable('employees') && Schema::hasTable('leave_credits')) {
            foreach (['VACATION', 'SICK'] as $code) {
                $leaveTypeId = DB::table('leave_types')->where('code', $code)->value('id');
                if ($leaveTypeId === null) {
                    continue;
                }

                $employees = DB::table('employees')->pluck('id');
                foreach ($employees as $employeeId) {
                    DB::table('leave_credits')->insertOrIgnore([
                        'employee_id' => $employeeId,
                        'leave_type_id' => $leaveTypeId,
                        'beginning_balance' => 0,
                        'earned_credits' => 0,
                        'used_credits' => 0,
                        'adjustments' => 0,
                        'effective_date' => now()->toDateString(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('leave_applications', function (Blueprint $table) {
            $table->dropForeign(['eligibility_verified_by']);
            $table->dropColumn(['policy_details', 'eligibility_verified_at', 'eligibility_verified_by', 'eligibility_remarks']);
        });

        Schema::table('leave_credit_transactions', function (Blueprint $table) {
            $table->dropUnique('leave_credit_monthly_accrual_unique');
            $table->dropColumn(['previous_balance', 'accrual_month']);
        });
    }
};
