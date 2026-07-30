<?php

return [
    'summary' => ':action :ports von :source',
    'anywhere' => 'Überall',
    'actions' => [
        'allow' => 'Erlauben',
        'deny' => 'Verweigern',
    ],
    'presets' => [
        'ssh' => 'SSH',
        'http' => 'HTTP',
        'https' => 'HTTPS',
        'mysql' => 'MySQL',
        'postgresql' => 'PostgreSQL',
        'redis' => 'Redis',
        'ftp' => 'FTP',
        'smtp' => 'SMTP',
        'dns' => 'DNS',
        'custom' => 'Benutzerdefinierter Port',
    ],

    'risky' => [
        'database' => ':engine ist eine Datenbank — aus dem Internet erreichbar, ist sie ein Zugang zu Ihren Daten.',
        'service' => ':service sollte nicht aus dem Internet erreichbar sein.',
    ],
];
