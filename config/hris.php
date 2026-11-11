<?php

return [
    'initial_accounts' => [
        'super_admin' => [
            'name' => env('HRIS_INITIAL_SUPER_ADMIN_NAME'),
            'email' => env('HRIS_INITIAL_SUPER_ADMIN_EMAIL'),
            'password' => env('HRIS_INITIAL_SUPER_ADMIN_PASSWORD'),
        ],
        'admin' => [
            'name' => env('HRIS_INITIAL_ADMIN_NAME'),
            'email' => env('HRIS_INITIAL_ADMIN_EMAIL'),
            'password' => env('HRIS_INITIAL_ADMIN_PASSWORD'),
            'can_final_approve' => (bool) env('HRIS_INITIAL_ADMIN_FINAL_APPROVER', false),
        ],
        'hrmo' => [
            'name' => env('HRIS_INITIAL_HRMO_NAME'),
            'email' => env('HRIS_INITIAL_HRMO_EMAIL'),
            'password' => env('HRIS_INITIAL_HRMO_PASSWORD'),
        ],
        'office_head' => [
            'name' => env('HRIS_INITIAL_OFFICE_HEAD_NAME'),
            'email' => env('HRIS_INITIAL_OFFICE_HEAD_EMAIL'),
            'password' => env('HRIS_INITIAL_OFFICE_HEAD_PASSWORD'),
            'department_id' => env('HRIS_INITIAL_OFFICE_HEAD_DEPARTMENT_ID'),
        ],
    ],
];
