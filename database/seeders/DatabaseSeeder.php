<?php

namespace Database\Seeders;

use App\Models\LeaveType;
use App\Models\Employee;
use Illuminate\Database\Seeder;
use App\Services\LeaveCreditAccountService;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(ConfiguredAccountSeeder::class);

        $types = [
            ['Vacation Leave', 'VACATION', 'File at least five days in advance whenever possible. Confirm applicable filing requirements with HRMO.', [], true, false],
            ['Mandatory/Forced Leave', 'FORCED', 'HR verifies current mandatory leave policy and remaining balances.', [], false, false],
            ['Sick Leave', 'SICK', 'File immediately upon return. A medical certificate is required for an absence of five calendar days or more; HRMO will review documentation when a physician was not consulted.', [], true, true],
            ['Maternity Leave', 'MATERNITY', 'Entitlement and recipient-agency allocation rules require HRMO eligibility review.', ['Ultrasound or doctor certificate indicating the expected date of delivery'], false, true],
            ['Paternity Leave', 'PATERNITY', 'Supporting requirements are subject to current policy and HR review.', ['Child birth certificate', 'Medical certificate when applicable', 'Marriage contract when required'], false, true],
            ['Special Privilege Leave', 'SPECIAL_PRIVILEGE', 'File at least one week in advance when possible. For travel within the Philippines or abroad, attach the applicable travel authority and clearance from money and property accountabilities.', [], false, false],
            ['Solo Parent Leave', 'SOLO_PARENT', 'File at least five days in advance whenever possible. Confirm current eligibility and documentary requirements with HR.', ['Updated Solo Parent Identification Card'], false, true],
            ['Study Leave', 'STUDY', 'Requirements are subject to agency policy and approval.', ['Agency requirements', 'Agreement with the agency'], false, false],
            ['10-Day VAWC Leave', 'VAWC', 'Sensitive documents are restricted to the employee, HRMO, and authorized administrator.', [], false, true],
            ['Rehabilitation Leave', 'REHABILITATION', 'Filing periods and documentation are subject to current policy.', ['Incident or accident report', 'Relevant medical reports', 'Medical certificate describing injury, recuperation, and rehabilitation'], false, true],
            ['Special Leave Benefits for Women', 'WOMEN_SPECIAL', 'File at least five days in advance when possible. In emergencies, submit immediately upon return and provide the required medical documentation.', ['Medical certificate', 'Clinical summary', 'Relevant report and recovery period'], false, true],
            ['Special Emergency (Calamity) Leave', 'CALAMITY', 'Use within 30 days of the calamity occurrence and at most once per year, subject to HRMO verification.', ['Government agency declaration or certification', 'Proof of residence or affected area'], false, false],
            ['Adoption Leave', 'ADOPTION', 'Requirements are subject to current applicable rules.', ['Authenticated Pre-Adoptive Placement Authority when required'], false, true],
            ['Others – specify', 'OTHER', 'Specify the leave type and consult HR about applicable policy and supporting documents.', ['Additional supporting documents as directed by HR'], false, false],
            ['Monetization of Leave Credits', 'MONETIZATION', 'Requires an agency-head decision and a letter request stating valid and justifiable reasons. This records leave-credit administration only.', ['Letter request stating valid and justifiable reasons'], false, false],
            ['Terminal Leave', 'TERMINAL', 'Submit proof of resignation, retirement, or separation. HRMO records the remaining leave credits for processing; this module does not calculate monetary payouts.', ['Proof of resignation, retirement, or separation from service'], false, true],
            ['Wellness Leave', 'WELLNESS', 'Local five-day Wellness Leave category. HRMO must configure eligibility and determine whether an individual balance is granted or consumed.', [], false, false],
        ];

        $policyDefaults = [
            'VACATION' => [
                'annual_entitlement_days' => 15,
                'monthly_accrual' => 1.25,
                'accrual_eligibility' => ['employment_statuses' => ['Active'], 'employment_types' => [], 'require_date_hired' => true, 'accrue_after_full_month' => true],
                'consumes_regular_credit' => true,
                'filing_advance_days' => 5,
            ],
            'SICK' => [
                'annual_entitlement_days' => 15,
                'monthly_accrual' => 1.25,
                'accrual_eligibility' => ['employment_statuses' => ['Active'], 'employment_types' => [], 'require_date_hired' => true, 'accrue_after_full_month' => true],
                'consumes_regular_credit' => true,
                'conditional_required_documents' => [
                    ['document' => 'Medical certificate when required', 'condition' => 'minimum_calendar_days', 'value' => 5],
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
                'entitlement_days' => 7,
                'entitlement_period' => 'annual',
                'filing_advance_days' => 5,
                'eligibility_review_required' => true,
                'application_fields' => [['key' => 'solo_parent_id_expiry', 'label' => 'Solo Parent ID expiry date', 'type' => 'date', 'required' => true]],
            ],
            'STUDY' => [
                'max_entitlement_months' => 6,
                'eligibility_review_required' => true,
                'application_fields' => [['key' => 'study_purpose', 'label' => 'Study purpose and program', 'type' => 'textarea', 'required' => true]],
            ],
            'VAWC' => [
                'entitlement_days' => 10,
                'entitlement_period' => 'annual',
                'filing_advance_optional' => true,
                'eligibility_review_required' => true,
                'conditional_required_documents' => [
                    ['document' => 'Punong Barangay/Kagawad or court certification while protection order is pending', 'condition' => 'policy_detail_equals', 'field' => 'protection_order_status', 'value' => 'pending'],
                    ['document' => 'Barangay Protection Order (BPO), Temporary Protection Order (TPO), or Permanent Protection Order (PPO)', 'condition' => 'policy_detail_equals', 'field' => 'protection_order_status', 'value' => 'issued'],
                ],
                'application_fields' => [['key' => 'protection_order_status', 'label' => 'Protection order status', 'type' => 'select', 'required' => true, 'options' => [['value' => 'issued', 'label' => 'Issued'], ['value' => 'pending', 'label' => 'Pending']]]],
            ],
            'REHABILITATION' => [
                'max_entitlement_months' => 6,
                'eligibility_review_required' => true,
                'application_fields' => [
                    ['key' => 'incident_date', 'label' => 'Incident or accident date', 'type' => 'date', 'required' => true],
                    ['key' => 'private_attending_physician', 'label' => 'Is the attending physician in private practice?', 'type' => 'select', 'required' => false, 'options' => [['value' => 'yes', 'label' => 'Yes'], ['value' => 'no', 'label' => 'No']]],
                ],
                'conditional_required_documents' => [
                    ['document' => 'Written concurrence of a government physician', 'condition' => 'policy_detail_equals', 'field' => 'private_attending_physician', 'value' => 'yes'],
                ],
            ],
            'WOMEN_SPECIAL' => [
                'max_entitlement_months' => 2,
                'filing_advance_days' => 5,
                'emergency_return_filing_allowed' => true,
                'application_fields' => [
                    ['key' => 'surgery_date', 'label' => 'Scheduled or completed surgery date', 'type' => 'date', 'required' => true],
                    ['key' => 'emergency_case', 'label' => 'Emergency filing?', 'type' => 'select', 'required' => false, 'options' => [['value' => 'yes', 'label' => 'Yes'], ['value' => 'no', 'label' => 'No']]],
                ],
            ],
            'CALAMITY' => [
                'entitlement_days' => 5,
                'entitlement_period' => 'annual',
                'use_within_occurrence_days' => 30,
                'maximum_uses_per_year' => 1,
                'eligibility_review_required' => true,
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
                'minimum_credit_fraction' => 0.5,
                'requires_agency_head_decision' => true,
                'not_absence_leave' => true,
                'application_fields' => [
                    ['key' => 'requested_credit_days', 'label' => 'Leave credit days requested for monetization', 'type' => 'number', 'required' => true],
                    ['key' => 'justifiable_reason', 'label' => 'Valid and justifiable reason', 'type' => 'textarea', 'required' => true],
                ],
            ],
            'TERMINAL' => [
                'requires_separation_proof' => true,
                'not_absence_leave' => true,
                'monetary_payout_calculation' => false,
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

        foreach ($types as $index => [$name, $code, $guidance, $documents, $requiresCredit, $sensitive]) {
            LeaveType::firstOrCreate(['code' => $code], [
                'name' => $name,
                'guidance' => $guidance,
                'required_documents' => $documents,
                'rules' => array_merge([
                    'filing_advance_days' => null,
                    'max_working_days' => null,
                    'policy_review_required' => true,
                ], $policyDefaults[$code] ?? []),
                'requires_credit' => $requiresCredit,
                'is_sensitive' => $sensitive,
                'is_active' => true,
                'sort_order' => $index + 1,
            ]);
        }

        $accountService = app(LeaveCreditAccountService::class);
        Employee::query()->whereNull('deleted_at')->orderBy('id')->chunkById(
            200,
            fn ($employees) => $employees->each(fn (Employee $employee) => $accountService->ensureRegularAccounts($employee))
        );
    }
}
