<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveCredit;
use App\Models\LeaveType;
use App\Models\Position;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $admin = User::updateOrCreate(['email' => 'admin@camalaniugan.gov.ph'], [
            'name' => 'HRIS Administrator',
            'role' => 'admin',
            'can_final_approve' => true,
            'password' => Hash::make('ChangeMe123!'),
        ]);
        $hrmo = User::updateOrCreate(['email' => 'hrmo@camalaniugan.gov.ph'], [
            'name' => 'HRMO Officer',
            'role' => 'hrmo',
            'password' => Hash::make('ChangeMe123!'),
        ]);
        $head = User::updateOrCreate(['email' => 'officehead@camalaniugan.gov.ph'], [
            'name' => 'Municipal Office Head',
            'role' => 'office_head',
            'password' => Hash::make('ChangeMe123!'),
        ]);
        $staff = User::updateOrCreate(['email' => 'employee@camalaniugan.gov.ph'], [
            'name' => 'Juan Dela Cruz',
            'role' => 'employee',
            'password' => Hash::make('ChangeMe123!'),
        ]);

        $department = Department::updateOrCreate(['code' => 'MHO'], [
            'name' => 'Municipal Health Office',
            'head_user_id' => $head->id,
            'is_active' => true,
        ]);
        $position = Position::updateOrCreate([
            'department_id' => $department->id,
            'title' => 'Administrative Aide',
        ], ['salary_grade' => 'SG 4', 'is_active' => true]);
        $employee = Employee::updateOrCreate(['employee_no' => 'CAM-0001'], [
            'user_id' => $staff->id,
            'department_id' => $department->id,
            'position_id' => $position->id,
            'biometric_id' => 'BIO-0001',
            'first_name' => 'Juan',
            'middle_name' => 'Santos',
            'last_name' => 'Dela Cruz',
            'sex' => 'Male',
            'date_of_birth' => '1990-04-15',
            'civil_status' => 'Single',
            'contact_number' => '09170000000',
            'email' => $staff->email,
            'address' => 'Camalaniugan, Cagayan',
            'employment_status' => 'Active',
            'employment_type' => 'Permanent',
            'date_hired' => '2020-01-06',
            'salary_grade' => 'SG 4',
            'salary_step' => 1,
            'monthly_salary' => 18000,
        ]);

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
            LeaveType::updateOrCreate(['code' => $code], [
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

        foreach (['VACATION', 'SICK'] as $code) {
            $type = LeaveType::where('code', $code)->firstOrFail();
            LeaveCredit::firstOrCreate([
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
            ], [
                'beginning_balance' => 0,
                'earned_credits' => $code === 'VACATION' ? 12 : 8,
                'effective_date' => today(),
            ]);
        }
    }
}
