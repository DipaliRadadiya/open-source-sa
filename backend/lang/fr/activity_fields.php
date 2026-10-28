<?php

/*
 * Field names for the activity log's "what changed" line (2026-10-28):
 * "SSH port 22 → 2222". By event type, then `common`; a field with no label
 * reads as its own name with spaces. PHP directives keep their real names.
 */

return [
    'common' => [
        'enabled' => 'Activé',
        'name' => 'Nom',
    ],
    'setting' => [
        'hostname' => 'Nom d\'hôte',
        'timezone' => 'Fuseau horaire',
        'ntp' => 'Synchronisation horaire (NTP)',
        'port' => 'Port SSH',
        'permit_root_login' => 'Connexion root',
        'password_authentication' => 'Connexion par mot de passe',
        'security_updates_enabled' => 'Mises à jour de sécurité automatiques',
        'auto_reboot' => 'Redémarrage après mises à jour',
        'reboot_time' => 'Heure de redémarrage',
        'reboot_with_users' => 'Redémarrer avec des utilisateurs connectés',
        'size_human' => 'Taille du swap',
        'maxmemory' => 'Limite mémoire Redis',
        'maxmemory_policy' => 'Politique d\'éviction Redis',
        'password' => 'Mot de passe Redis',
        'frequency' => 'Fréquence',
        'hour' => 'Heure',
        'day_of_week' => 'Jour de la semaine',
        'day_of_month' => 'Jour du mois',
    ],
    'application' => [
        'php_version' => 'Version de PHP',
        'additional_directives' => 'Directives supplémentaires',
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
        'username' => 'S\'exécute en tant que',
        'command' => 'Commande',
        'expression' => 'Planification',
        'active' => 'Active',
    ],
    'backup' => [
        'storage_destination' => 'Destination',
        'type' => 'Ce qui est sauvegardé',
        'frequency' => 'Fréquence',
        'schedule_time' => 'Heure',
        'retention_count' => 'Sauvegardes conservées',
        'file_excludes' => 'Fichiers exclus',
    ],
    'firewall' => [
        'description' => 'Description',
        'port_from' => 'Port',
        'port_to' => 'Fin de la plage de ports',
        'protocol' => 'Protocole',
        'action' => 'Action',
        'source_ip' => 'IP source',
    ],
];
