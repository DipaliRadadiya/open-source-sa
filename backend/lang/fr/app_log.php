<?php

return [
    'sources' => [
        'container' => 'Conteneur',
        'access' => 'Journal d\'accès',
        'error' => 'Journal d\'erreurs',
        'application' => 'Sortie de l\'application',
        'application_error' => 'Erreurs de l\'application',
        'waf_detect' => 'Détections du pare-feu',
    ],

    'errors' => [

        'not_downloadable' => 'Ce journal n\'est pas un fichier : la sortie d\'un conteneur est conservée par Docker. Consultez-la à l\'écran.',
        'unknown_source' => 'Ce journal n\'existe pas pour cette application.',
        'clear_shared' => 'Sous OpenLiteSpeed, les détections du pare-feu font partie du journal d\'accès de l’application. Videz plutôt le journal d\'accès.',
    ],
];
