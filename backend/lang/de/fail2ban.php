<?php

return [
    'install_started' => 'fail2ban wird installiert. Das dauert einen Moment.',

    'bantime' => [
        '10m' => '10 Minuten',
        '1h' => '1 Stunde',
        '1d' => '1 Tag',
        '1w' => '1 Woche',
        'permanent' => 'Dauerhaft',
    ],

    'created_successfully' => 'Fail2ban wurde erfolgreich konfiguriert!',
    'test_failed' => 'Der Fail2ban-Konfigurationstest ist fehlgeschlagen.',
    'already_disabled' => 'Fail2ban ist für diese Anwendung bereits deaktiviert.',
    'disabled_successfully' => 'Fail2ban wurde erfolgreich deaktiviert!',

    'validation' => [
        'jail_content_required' => 'Die Jail-Konfiguration ist erforderlich.',
        'jail_content_string' => 'Die Jail-Konfiguration muss Text sein.',
        'jail_content_max' => 'Die Jail-Konfiguration ist zu groß (max. 65535 Zeichen).',
        'filter_content_required' => 'Die Filter-Konfiguration ist erforderlich.',
        'filter_content_string' => 'Die Filter-Konfiguration muss Text sein.',
        'filter_content_max' => 'Die Filter-Konfiguration ist zu groß (max. 65535 Zeichen).',
    ],
];
