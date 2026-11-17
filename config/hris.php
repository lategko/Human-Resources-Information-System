<?php

return [
    'employee_import' => [
        'department_aliases' => [],
        // Only exact HR-confirmed titles grant the Office Head role.
        'office_head_designations' => [],
    ],
    'initial_accounts' => [
        'admin' => [
            'name' => env('HRIS_INITIAL_ADMIN_NAME'),
            'email' => env('HRIS_INITIAL_ADMIN_EMAIL'),
            'password' => env('HRIS_INITIAL_ADMIN_PASSWORD'),
            'role' => 'admin',
            'employee_no' => env('HRIS_INITIAL_ADMIN_EMPLOYEE_NO'),
            'department_id' => env('HRIS_INITIAL_ADMIN_DEPARTMENT_ID'),
        ],
        'mayor' => [
            'name' => env('HRIS_INITIAL_MAYOR_NAME'),
            'email' => env('HRIS_INITIAL_MAYOR_EMAIL'),
            'password' => env('HRIS_INITIAL_MAYOR_PASSWORD'),
            'role' => 'mayor',
            'employee_no' => env('HRIS_INITIAL_MAYOR_EMPLOYEE_NO'),
            'department_id' => env('HRIS_INITIAL_MAYOR_DEPARTMENT_ID'),
        ],
        'office_head' => [
            'name' => env('HRIS_INITIAL_OFFICE_HEAD_NAME'),
            'email' => env('HRIS_INITIAL_OFFICE_HEAD_EMAIL'),
            'password' => env('HRIS_INITIAL_OFFICE_HEAD_PASSWORD'),
            'role' => 'office_head',
            'employee_no' => env('HRIS_INITIAL_OFFICE_HEAD_EMPLOYEE_NO'),
            'department_id' => env('HRIS_INITIAL_OFFICE_HEAD_DEPARTMENT_ID'),
        ],
        'employee' => [
            'name' => env('HRIS_INITIAL_EMPLOYEE_NAME'),
            'email' => env('HRIS_INITIAL_EMPLOYEE_EMAIL'),
            'password' => env('HRIS_INITIAL_EMPLOYEE_PASSWORD'),
            'role' => 'employee',
            'employee_no' => env('HRIS_INITIAL_EMPLOYEE_EMPLOYEE_NO', env('HRIS_INITIAL_EMPLOYEE_NO')),
            'department_id' => env('HRIS_INITIAL_EMPLOYEE_DEPARTMENT_ID'),
        ],
    ],
];
