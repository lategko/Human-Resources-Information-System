<?php

return [
    'directory' => env('HRIS_BACKUP_DIRECTORY', 'hris-backups'),
    'mysqldump_binary' => env('MYSQLDUMP_BINARY', 'mysqldump'),
    'mysql_binary' => env('MYSQL_BINARY', 'mysql'),
    'process_timeout' => (int) env('HRIS_BACKUP_PROCESS_TIMEOUT', 3600),
];
