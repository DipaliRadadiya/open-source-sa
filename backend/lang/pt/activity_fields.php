<?php

/*
 * Field names for the activity log's "what changed" line (2026-10-28):
 * "SSH port 22 → 2222". By event type, then `common`; a field with no label
 * reads as its own name with spaces. PHP directives keep their real names.
 */

return [
    'common' => [
        'enabled' => 'Ativado',
        'name' => 'Nome',
    ],
    'setting' => [
        'hostname' => 'Nome do anfitrião',
        'timezone' => 'Fuso horário',
        'ntp' => 'Sincronização de hora (NTP)',
        'port' => 'Porta SSH',
        'permit_root_login' => 'Início de sessão root',
        'password_authentication' => 'Início de sessão com palavra-passe',
        'security_updates_enabled' => 'Atualizações de segurança automáticas',
        'auto_reboot' => 'Reiniciar após atualizações',
        'reboot_time' => 'Hora de reinício',
        'reboot_with_users' => 'Reiniciar com utilizadores ligados',
        'size_human' => 'Tamanho da swap',
        'maxmemory' => 'Limite de memória do Redis',
        'maxmemory_policy' => 'Política de remoção do Redis',
        'password' => 'Palavra-passe do Redis',
        'frequency' => 'Frequência',
        'hour' => 'Hora',
        'day_of_week' => 'Dia da semana',
        'day_of_month' => 'Dia do mês',
    ],
    'application' => [
        'php_version' => 'Versão do PHP',
        'additional_directives' => 'Diretivas adicionais',
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
        'username' => 'Corre como',
        'command' => 'Comando',
        'expression' => 'Agendamento',
        'active' => 'Ativa',
    ],
    'backup' => [
        'storage_destination' => 'Destino',
        'type' => 'O que é copiado',
        'frequency' => 'Frequência',
        'schedule_time' => 'Hora',
        'retention_count' => 'Cópias mantidas',
        'file_excludes' => 'Ficheiros excluídos',
    ],
    'firewall' => [
        'description' => 'Descrição',
        'port_from' => 'Porta',
        'port_to' => 'Fim do intervalo de portas',
        'protocol' => 'Protocolo',
        'action' => 'Ação',
        'source_ip' => 'IP de origem',
    ],
];
