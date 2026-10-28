<?php

/*
 * Field names for the activity log's "what changed" line (2026-10-28):
 * "SSH port 22 → 2222". By event type, then `common`; a field with no label
 * reads as its own name with spaces. PHP directives keep their real names.
 */

return [
    'common' => [
        'enabled' => 'Activado',
        'name' => 'Nombre',
    ],
    'setting' => [
        'hostname' => 'Nombre del host',
        'timezone' => 'Zona horaria',
        'ntp' => 'Sincronización horaria (NTP)',
        'port' => 'Puerto SSH',
        'permit_root_login' => 'Inicio de sesión de root',
        'password_authentication' => 'Inicio de sesión con contraseña',
        'security_updates_enabled' => 'Actualizaciones de seguridad automáticas',
        'auto_reboot' => 'Reinicio tras actualizaciones',
        'reboot_time' => 'Hora de reinicio',
        'reboot_with_users' => 'Reiniciar con usuarios conectados',
        'size_human' => 'Tamaño de swap',
        'maxmemory' => 'Límite de memoria de Redis',
        'maxmemory_policy' => 'Política de expulsión de Redis',
        'password' => 'Contraseña de Redis',
        'frequency' => 'Frecuencia',
        'hour' => 'Hora',
        'day_of_week' => 'Día de la semana',
        'day_of_month' => 'Día del mes',
    ],
    'application' => [
        'php_version' => 'Versión de PHP',
        'additional_directives' => 'Directivas adicionales',
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
        'username' => 'Se ejecuta como',
        'command' => 'Comando',
        'expression' => 'Programación',
        'active' => 'Activa',
    ],
    'backup' => [
        'storage_destination' => 'Destino',
        'type' => 'Qué se copia',
        'frequency' => 'Frecuencia',
        'schedule_time' => 'Hora',
        'retention_count' => 'Copias conservadas',
        'file_excludes' => 'Archivos excluidos',
    ],
    'firewall' => [
        'description' => 'Descripción',
        'port_from' => 'Puerto',
        'port_to' => 'Fin del rango de puertos',
        'protocol' => 'Protocolo',
        'action' => 'Acción',
        'source_ip' => 'IP de origen',
    ],
];
