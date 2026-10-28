<?php

/*
 * Field names for the activity log's "what changed" line (2026-10-28):
 * "SSH port 22 → 2222". By event type, then `common`; a field with no label
 * reads as its own name with spaces. PHP directives keep their real names.
 */

return [
    'common' => [
        'enabled' => 'Enabled',
        'name' => 'Name',
    ],
    'setting' => [
        'hostname' => 'Hostname',
        'timezone' => 'Timezone',
        'ntp' => 'Time sync (NTP)',
        'port' => 'SSH port',
        'permit_root_login' => 'Root login',
        'password_authentication' => 'Password login',
        'security_updates_enabled' => 'Automatic security updates',
        'auto_reboot' => 'Reboot after updates',
        'reboot_time' => 'Reboot time',
        'reboot_with_users' => 'Reboot while users are logged in',
        'size_human' => 'Swap size',
        'maxmemory' => 'Redis memory limit',
        'maxmemory_policy' => 'Redis eviction policy',
        'password' => 'Redis password',
        'frequency' => 'Frequency',
        'hour' => 'Hour',
        'day_of_week' => 'Day of the week',
        'day_of_month' => 'Day of the month',
    ],
    'application' => [
        'php_version' => 'PHP version',
        'additional_directives' => 'Additional directives',
        'memory_limit' => 'memory_limit',
        'upload_max_filesize' => 'upload_max_filesize',
        'post_max_size' => 'post_max_size',
        'max_execution_time' => 'max_execution_time',
        'max_input_time' => 'max_input_time',
        'max_input_vars' => 'max_input_vars',
        'session_gc_maxlifetime' => 'session.gc_maxlifetime',
        'pm_type' => 'pm',
        'pm_max_children' => 'pm.max_children',
        'pm_max_requests' => 'pm.max_requests',
        'pm_start_servers' => 'pm.start_servers',
        'pm_min_spare_servers' => 'pm.min_spare_servers',
        'pm_max_spare_servers' => 'pm.max_spare_servers',
        'pm_process_idle_timeout' => 'pm.process_idle_timeout',
        'pm_max_spawn_rate' => 'pm.max_spawn_rate',
        'open_basedir_enabled' => 'open_basedir',
        'open_basedir_paths' => 'open_basedir paths',
        'disable_functions' => 'disable_functions',
        'allow_url_fopen' => 'allow_url_fopen',
        'php_timezone' => 'date.timezone',
        'auto_prepend_file' => 'auto_prepend_file',
    ],
    'cronjob' => [
        'username' => 'Runs as',
        'command' => 'Command',
        'expression' => 'Schedule',
        'active' => 'Active',
    ],
    'backup' => [
        'storage_destination' => 'Destination',
        'type' => 'What is backed up',
        'frequency' => 'Frequency',
        'schedule_time' => 'Time',
        'retention_count' => 'Backups kept',
        'file_excludes' => 'Excluded files',
    ],
    'firewall' => [
        'description' => 'Description',
        'port_from' => 'Port',
        'port_to' => 'Port range end',
        'protocol' => 'Protocol',
        'action' => 'Action',
        'source_ip' => 'Source IP',
    ],
];
