<?php

return [
    'issues' => [
        'certificate' => [
            'expired' => 'SSL certificate has expired.',
            'expiring' => 'SSL certificate expires in :days days.',
        ],
        'worker' => [
            'stopped' => 'Application process is stopped.',
        ],
        'dns' => [
            'drift' => 'DNS has drifted — the domain IP no longer matches the stored value.',
            'unresolved' => 'Domain DNS cannot be resolved right now.',
        ],
        'php_eol' => 'PHP :version is end-of-life and no longer receives security updates.',
        'disk' => 'Disk usage is at :percent%.',
        'deploy_failed' => 'Last deployment failed at the :step step.',
    ],
];
