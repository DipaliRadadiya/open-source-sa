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
    'rejected' => 'Fail2ban a refusé cette configuration, rien n\'a donc été modifié.',
    'already_disabled' => 'Fail2ban est déjà désactivé pour cette application.',
    'disabled_successfully' => 'Fail2ban désactivé avec succès !',

    'validation' => [
        'jail_content_required' => 'La configuration de la prison est obligatoire.',
        'jail_content_string' => 'La configuration de la prison doit être du texte.',
        'jail_content_max' => 'La configuration de la prison est trop volumineuse (max. 65535 caractères).',
        'filter_content_required' => 'La configuration du filtre est obligatoire.',
        'filter_content_string' => 'La configuration du filtre doit être du texte.',
        'filter_content_max' => 'La configuration du filtre est trop volumineuse (max. 65535 caractères).',
        'foreign_jail' => 'Cette jail ne peut configurer que la jail propre à cette application. Remplacez [:section] par [{name}] (devient :name).',
        'foreign_filter' => 'Cette jail ne peut utiliser que le filtre propre à cette application. Remplacez filter = :filter par filter = {filter} (devient :name).',
        'disallowed_setting' => 'Le réglage « :setting » n\'est pas autorisé dans la jail d\'une application. Autorisés : :allowed.',
    ],
    // FB-wp: the default filter is WordPress's.
    'app_default_filter_wordpress_only' => 'Les règles par défaut ne détectent que les connexions WordPress échouées. :type n’a pas encore de telles règles : cette prison ne bannit donc personne tant que vous n’ajoutez pas de règle pour la page de connexion de cette application.',
];
