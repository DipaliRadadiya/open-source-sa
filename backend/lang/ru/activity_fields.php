<?php

/*
 * Field names for the activity log's "what changed" line (2026-10-28):
 * "SSH port 22 → 2222". By event type, then `common`; a field with no label
 * reads as its own name with spaces. PHP directives keep their real names.
 */

return [
    'common' => [
        'enabled' => 'Включено',
        'name' => 'Имя',
    ],
    'setting' => [
        'hostname' => 'Имя хоста',
        'timezone' => 'Часовой пояс',
        'ntp' => 'Синхронизация времени (NTP)',
        'port' => 'SSH-порт',
        'permit_root_login' => 'Вход root',
        'password_authentication' => 'Вход по паролю',
        'security_updates_enabled' => 'Автоматические обновления безопасности',
        'auto_reboot' => 'Перезагрузка после обновлений',
        'reboot_time' => 'Время перезагрузки',
        'reboot_with_users' => 'Перезагрузка при вошедших пользователях',
        'size_human' => 'Размер swap',
        'maxmemory' => 'Лимит памяти Redis',
        'maxmemory_policy' => 'Политика вытеснения Redis',
        'password' => 'Пароль Redis',
        'frequency' => 'Частота',
        'hour' => 'Час',
        'day_of_week' => 'День недели',
        'day_of_month' => 'День месяца',
    ],
    'application' => [
        'php_version' => 'Версия PHP',
        'additional_directives' => 'Дополнительные директивы',
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
        'username' => 'Запускается от имени',
        'command' => 'Команда',
        'expression' => 'Расписание',
        'active' => 'Активна',
    ],
    'backup' => [
        'storage_destination' => 'Хранилище',
        'type' => 'Что копируется',
        'frequency' => 'Частота',
        'schedule_time' => 'Время',
        'retention_count' => 'Хранить копий',
        'file_excludes' => 'Исключённые файлы',
    ],
    'firewall' => [
        'description' => 'Описание',
        'port_from' => 'Порт',
        'port_to' => 'Конец диапазона портов',
        'protocol' => 'Протокол',
        'action' => 'Действие',
        'source_ip' => 'IP-источник',
    ],
];
