<?php

/*
 * Field names for the activity log's "what changed" line (2026-10-28):
 * "SSH port 22 → 2222". By event type, then `common`; a field with no label
 * reads as its own name with spaces. PHP directives keep their real names.
 */

return [
    'common' => [
        'enabled' => 'चालू',
        'name' => 'नाम',
    ],
    'setting' => [
        'hostname' => 'होस्टनेम',
        'timezone' => 'टाइमज़ोन',
        'ntp' => 'समय सिंक (NTP)',
        'port' => 'SSH पोर्ट',
        'permit_root_login' => 'रूट लॉगिन',
        'password_authentication' => 'पासवर्ड लॉगिन',
        'security_updates_enabled' => 'स्वचालित सुरक्षा अपडेट',
        'auto_reboot' => 'अपडेट के बाद रीबूट',
        'reboot_time' => 'रीबूट का समय',
        'reboot_with_users' => 'उपयोगकर्ता लॉग इन होने पर रीबूट',
        'size_human' => 'स्वैप आकार',
        'maxmemory' => 'Redis मेमोरी सीमा',
        'maxmemory_policy' => 'Redis इविक्शन नीति',
        'password' => 'Redis पासवर्ड',
        'frequency' => 'आवृत्ति',
        'hour' => 'घंटा',
        'day_of_week' => 'सप्ताह का दिन',
        'day_of_month' => 'महीने का दिन',
    ],
    'application' => [
        'php_version' => 'PHP वर्शन',
        'additional_directives' => 'अतिरिक्त निर्देश',
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
        'username' => 'इस रूप में चलता है',
        'command' => 'कमांड',
        'expression' => 'शेड्यूल',
        'active' => 'सक्रिय',
    ],
    'backup' => [
        'storage_destination' => 'डेस्टिनेशन',
        'type' => 'क्या बैकअप होता है',
        'frequency' => 'आवृत्ति',
        'schedule_time' => 'समय',
        'retention_count' => 'रखे गए बैकअप',
        'file_excludes' => 'बाहर रखी गई फ़ाइलें',
    ],
    'firewall' => [
        'description' => 'विवरण',
        'port_from' => 'पोर्ट',
        'port_to' => 'पोर्ट रेंज का अंत',
        'protocol' => 'प्रोटोकॉल',
        'action' => 'कार्रवाई',
        'source_ip' => 'स्रोत IP',
    ],
];
