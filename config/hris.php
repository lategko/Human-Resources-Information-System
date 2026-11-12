<?php

return [
    'initial_accounts' => [
        // Initial Super Admin account: manages system-level user roles and access.
        'super_admin' => [
            'name' => env('HRIS_INITIAL_SUPER_ADMIN_NAME'),
            'email' => env('HRIS_INITIAL_SUPER_ADMIN_EMAIL'),
            'password' => env('HRIS_INITIAL_SUPER_ADMIN_PASSWORD'),
        ],
        // Initial Admin account: administers the HRIS and may optionally receive final-approval access.
        'admin' => [
            'name' => env('HRIS_INITIAL_ADMIN_NAME'),
            'email' => env('HRIS_INITIAL_ADMIN_EMAIL'),
            'password' => env('HRIS_INITIAL_ADMIN_PASSWORD'),
            'can_final_approve' => (bool) env('HRIS_INITIAL_ADMIN_FINAL_APPROVER', false),
        ],
        // Initial HRMO account: manages human resource records and personnel workflows.
        'hrmo' => [
            'name' => env('HRIS_INITIAL_HRMO_NAME'),
            'email' => env('HRIS_INITIAL_HRMO_EMAIL'),
            'password' => env('HRIS_INITIAL_HRMO_PASSWORD'),
        ],
        // Initial Office Head account: assigned to a department for its review and approval workflow.
        'office_head' => [
            'name' => env('HRIS_INITIAL_OFFICE_HEAD_NAME'),
            'email' => env('HRIS_INITIAL_OFFICE_HEAD_EMAIL'),
            'password' => env('HRIS_INITIAL_OFFICE_HEAD_PASSWORD'),
            'department_id' => env('HRIS_INITIAL_OFFICE_HEAD_DEPARTMENT_ID'),
        ],
    ],
];
