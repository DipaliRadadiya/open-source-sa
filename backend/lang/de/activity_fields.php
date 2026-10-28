<?php

/*
 * Field names for the activity log's "what changed" line (2026-10-28):
 * "SSH port 22 → 2222". By event type, then `common`; a field with no label
 * reads as its own name with spaces. PHP directives keep their real names.
 */

return [
    'common' => [
        'enabled' => 'Aktiviert',
        'name' => 'Name',
    ],
    'setting' => [
        'hostname' => 'Hostname',
        'timezone' => 'Zeitzone',
        'ntp' => 'Zeitsynchronisation (NTP)',
        'port' => 'SSH-Port',
        'permit_root_login' => 'Root-Anmeldung',
        'password_authentication' => 'Passwort-Anmeldung',
        'security_updates_enabled' => 'Automatische Sicherheitsupdates',
        'auto_reboot' => 'Neustart nach Updates',
        'reboot_time' => 'Neustartzeit',
        'reboot_with_users' => 'Neustart bei angemeldeten Benutzern',
        'size_human' => 'Swap-Größe',
        'maxmemory' => 'Redis-Speicherlimit',
        'maxmemory_policy' => 'Redis-Verdrängungsrichtlinie',
        'password' => 'Redis-Passwort',
        'frequency' => 'Häufigkeit',
        'hour' => 'Stunde',
        'day_of_week' => 'Wochentag',
        'day_of_month' => 'Tag des Monats',
    ],
    'application' => [
        'php_version' => 'PHP-Version',
        'additional_directives' => 'Zusätzliche Direktiven',
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
        'username' => 'Läuft als',
        'command' => 'Befehl',
        'expression' => 'Zeitplan',
        'active' => 'Aktiv',
    ],
    'backup' => [
        'storage_destination' => 'Ziel',
        'type' => 'Was gesichert wird',
        'frequency' => 'Häufigkeit',
        'schedule_time' => 'Uhrzeit',
        'retention_count' => 'Aufbewahrte Backups',
        'file_excludes' => 'Ausgeschlossene Dateien',
    ],
    'firewall' => [
        'description' => 'Beschreibung',
        'port_from' => 'Port',
        'port_to' => 'Ende des Portbereichs',
        'protocol' => 'Protokoll',
        'action' => 'Aktion',
        'source_ip' => 'Quell-IP',
    ],
];
