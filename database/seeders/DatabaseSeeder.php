<?php

namespace Database\Seeders;

use App\Models\LeaveType;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(ConfiguredAccountSeeder::class);

        $types = [
            ['Vacation Leave', 'VACATION', 'Normally file in advance where possible. If leave will be spent abroad, provide the destination. Verify current filing rules with HR.', ['Travel destination, if applicable'], true, false],
            ['Mandatory/Forced Leave', 'FORCED', 'HR verifies current mandatory leave policy and remaining balances.', [], false, false],
            ['Sick Leave', 'SICK', 'For advance filing or an absence exceeding five days, HR may require medical documentation under current policy.', ['Medical certificate when required', 'Explanation if consultation was unavailable'], true, true],
            ['Maternity Leave', 'MATERNITY', 'Supporting requirements are subject to current policy and HR review.', ['Ultrasound or doctor certificate', 'Expected date of delivery', 'Notice of Allocation of Maternity Leave Credits, when applicable'], false, true],
            ['Paternity Leave', 'PATERNITY', 'Supporting requirements are subject to current policy and HR review.', ['Child birth certificate', 'Medical certificate when applicable', 'Marriage contract when required'], false, true],
            ['Special Privilege Leave', 'SPECIAL_PRIVILEGE', 'Normally file at least one week in advance where possible; emergency exceptions require HR review.', ['Travel destination, if applicable'], false, false],
            ['Solo Parent Leave', 'SOLO_PARENT', 'Advance filing where possible. Confirm current eligibility and documentary requirements with HR.', ['Updated Solo Parent Identification Card'], false, true],
            ['Study Leave', 'STUDY', 'Requirements are subject to agency policy and approval.', ['Agency requirements', 'Agreement with the agency'], false, false],
            ['10-Day VAWC Leave', 'VAWC', 'Sensitive documents are restricted to the employee, HRMO, and authorized administrator.', ['Protection order or appropriate barangay/court certification'], false, true],
            ['Rehabilitation Leave', 'REHABILITATION', 'Filing periods and documentation are subject to current policy.', ['Letter request', 'Medical certificate and records', 'Police report when applicable'], false, true],
            ['Special Leave Benefits for Women', 'WOMEN_SPECIAL', 'Confirm current requirements with HR before filing.', ['Medical certificate', 'Clinical summary', 'Relevant report and recovery period'], false, true],
            ['Special Emergency (Calamity) Leave', 'CALAMITY', 'Eligibility, period, and maximum days require HR verification under current rules.', ['Circumstance and supporting information for verification'], false, false],
            ['Adoption Leave', 'ADOPTION', 'Requirements are subject to current applicable rules.', ['Authenticated Pre-Adoptive Placement Authority when required'], false, true],
            ['Others – specify', 'OTHER', 'Specify the leave type and consult HR about applicable policy and supporting documents.', ['Additional supporting documents as directed by HR'], false, false],
            ['Monetization of Leave Credits', 'MONETIZATION', 'Request requires an HR review of available credits and current government/municipal policy.', ['Letter request'], false, false],
            ['Terminal Leave', 'TERMINAL', 'Supporting separation documents and clearances are subject to HR review.', ['Resignation, retirement, or separation documents', 'Clearance documentation'], false, true],
        ];

        foreach ($types as $index => [$name, $code, $guidance, $documents, $requiresCredit, $sensitive]) {
            LeaveType::firstOrCreate(['code' => $code], [
                'name' => $name,
                'guidance' => $guidance,
                'required_documents' => $documents,
                'rules' => ['filing_advance_days' => null, 'max_working_days' => null, 'policy_review_required' => true],
                'requires_credit' => $requiresCredit,
                'is_sensitive' => $sensitive,
                'is_active' => true,
                'sort_order' => $index + 1,
            ]);
        }
    }
}
