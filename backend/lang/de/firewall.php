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
];
