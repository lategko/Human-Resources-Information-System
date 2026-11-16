<?php

return [
    'initial_accounts' => [
        'super_admin' => [
            'name' => env('HRIS_INITIAL_SUPER_ADMIN_NAME', env('INITIAL_SUPER_ADMIN_NAME')),
            'email' => env('HRIS_INITIAL_SUPER_ADMIN_EMAIL', env('INITIAL_SUPER_ADMIN_EMAIL')),
            'password' => env('HRIS_INITIAL_SUPER_ADMIN_PASSWORD', env('INITIAL_SUPER_ADMIN_PASSWORD')),
            'role' => env('HRIS_INITIAL_SUPER_ADMIN_ROLE', env('INITIAL_SUPER_ADMIN_ROLE', 'super_admin')),
            'is_active' => env('HRIS_INITIAL_SUPER_ADMIN_IS_ACTIVE', env('INITIAL_SUPER_ADMIN_IS_ACTIVE')),
        ],
        'admin' => [
            'name' => env('HRIS_INITIAL_ADMIN_NAME', env('INITIAL_ADMIN_NAME')),
            'email' => env('HRIS_INITIAL_ADMIN_EMAIL', env('INITIAL_ADMIN_EMAIL')),
            'password' => env('HRIS_INITIAL_ADMIN_PASSWORD', env('INITIAL_ADMIN_PASSWORD')),
            'role' => env('HRIS_INITIAL_ADMIN_ROLE', env('INITIAL_ADMIN_ROLE', 'admin')),
            'is_active' => env('HRIS_INITIAL_ADMIN_IS_ACTIVE', env('INITIAL_ADMIN_IS_ACTIVE')),
            'can_final_approve' => env('HRIS_INITIAL_ADMIN_FINAL_APPROVER', env('INITIAL_ADMIN_FINAL_APPROVER')),
        ],
        'hrmo' => [
            'name' => env('HRIS_INITIAL_HRMO_NAME', env('INITIAL_HRMO_NAME')),
            'email' => env('HRIS_INITIAL_HRMO_EMAIL', env('INITIAL_HRMO_EMAIL')),
            'password' => env('HRIS_INITIAL_HRMO_PASSWORD', env('INITIAL_HRMO_PASSWORD')),
            'role' => env('HRIS_INITIAL_HRMO_ROLE', env('INITIAL_HRMO_ROLE', 'hrmo')),
            'is_active' => env('HRIS_INITIAL_HRMO_IS_ACTIVE', env('INITIAL_HRMO_IS_ACTIVE')),
        ],
        'mayor' => [
            'name' => env('HRIS_INITIAL_MAYOR_NAME', env('INITIAL_MAYOR_NAME')),
            'email' => env('HRIS_INITIAL_MAYOR_EMAIL', env('INITIAL_MAYOR_EMAIL')),
            'password' => env('HRIS_INITIAL_MAYOR_PASSWORD', env('INITIAL_MAYOR_PASSWORD')),
            'role' => env('HRIS_INITIAL_MAYOR_ROLE', env('INITIAL_MAYOR_ROLE', 'mayor')),
            'is_active' => env('HRIS_INITIAL_MAYOR_IS_ACTIVE', env('INITIAL_MAYOR_IS_ACTIVE')),
        ],
        'office_head' => [
            'name' => env('HRIS_INITIAL_OFFICE_HEAD_NAME', env('INITIAL_OFFICE_HEAD_NAME')),
            'email' => env('HRIS_INITIAL_OFFICE_HEAD_EMAIL', env('INITIAL_OFFICE_HEAD_EMAIL')),
            'password' => env('HRIS_INITIAL_OFFICE_HEAD_PASSWORD', env('INITIAL_OFFICE_HEAD_PASSWORD')),
            'role' => env('HRIS_INITIAL_OFFICE_HEAD_ROLE', env('INITIAL_OFFICE_HEAD_ROLE', 'office_head')),
            'is_active' => env('HRIS_INITIAL_OFFICE_HEAD_IS_ACTIVE', env('INITIAL_OFFICE_HEAD_IS_ACTIVE')),
            'department_id' => env('HRIS_INITIAL_OFFICE_HEAD_DEPARTMENT_ID', env('INITIAL_OFFICE_HEAD_DEPARTMENT_ID')),
        ],
    ],
];
