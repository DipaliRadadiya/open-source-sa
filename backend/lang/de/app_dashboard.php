<?php

return [
    'issues' => [
        'certificate' => [
            'expired' => 'Das SSL-Zertifikat ist abgelaufen.',
            'expiring' => 'Das SSL-Zertifikat läuft in :days Tagen ab.',
        ],
        'worker' => [
            'stopped' => 'Der Anwendungsprozess ist gestoppt.',
        ],
        'dns' => [
            'drift' => 'Das DNS weicht ab — die IP der Domain stimmt nicht mehr mit dem gespeicherten Wert überein.',
            'unresolved' => 'Das DNS der Domain kann derzeit nicht aufgelöst werden.',
        ],
        'php_eol' => 'PHP :version hat das Supportende erreicht und erhält keine Sicherheitsupdates mehr.',
        'disk' => 'Die Festplattenauslastung liegt bei :percent%.',
        'deploy_failed' => 'Die letzte Bereitstellung ist im Schritt :step fehlgeschlagen.',
    ],
];
