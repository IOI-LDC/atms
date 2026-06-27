<?php

return [
    'directory_source' => env('EMPLOYEE_DIRECTORY_SOURCE', 'csv'),

    'csv_path' => env('EMPLOYEE_CSV_PATH', base_path('employee.csv')),

    /**
     * If set, only these emp_ids will be visible in the employee list.
     * An explicit ?emp_ids= query parameter overrides this filter.
     * Set to null or empty array to show all employees from the directory.
     */
    'visible_emp_ids' => env('EMPLOYEE_VISIBLE_EMP_IDS')
        ? array_map('trim', explode(',', env('EMPLOYEE_VISIBLE_EMP_IDS')))
        : null,
];
