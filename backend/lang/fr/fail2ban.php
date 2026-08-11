<?php

return [
    'install_started' => 'Installation de fail2ban en cours. Cela prend un moment.',

    'bantime' => [
        '10m' => '10 minutes',
        '1h' => '1 heure',
        '1d' => '1 jour',
        '1w' => '1 semaine',
        'permanent' => 'Permanent',
    ],

    'created_successfully' => 'Fail2ban configuré avec succès !',
    'test_failed' => 'Le test de configuration de Fail2ban a échoué.',
    'already_disabled' => 'Fail2ban est déjà désactivé pour cette application.',
    'disabled_successfully' => 'Fail2ban désactivé avec succès !',

    'validation' => [
        'jail_content_required' => 'La configuration de la prison est obligatoire.',
        'jail_content_string' => 'La configuration de la prison doit être du texte.',
        'jail_content_max' => 'La configuration de la prison est trop volumineuse (max. 65535 caractères).',
        'filter_content_required' => 'La configuration du filtre est obligatoire.',
        'filter_content_string' => 'La configuration du filtre doit être du texte.',
        'filter_content_max' => 'La configuration du filtre est trop volumineuse (max. 65535 caractères).',
    ],
];
