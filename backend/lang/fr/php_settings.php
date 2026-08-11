<?php

return [
    'presets' => [
        'low' => [
            'title' => 'Faible trafic',
            'description' => 'Quelques processus. Convient à la plupart des petits sites et ménage un petit serveur.',
        ],
        'balanced' => [
            'title' => 'Équilibré',
            'description' => 'Absorbe un trafic normal sans réserver de la mémoire rarement utile.',
        ],
        'high' => [
            'title' => 'Fort trafic',
            'description' => 'Garde des processus prêts. À utiliser quand le site est réellement chargé : la mémoire est réservée, utilisée ou non.',
        ],
    ],

    'disable_functions_presets' => [
        'safe' => [
            'title' => 'Recommandé',
            'description' => 'Bloque tous les moyens d\'exécuter un programme depuis PHP — ce dont un web shell a besoin, et ce qu\'un site normal ne fait presque jamais.',
        ],
        'strict' => [
            'title' => 'Strict',
            'description' => 'Ajoute l\'inspection des processus, des utilisateurs et des sockets à la liste recommandée. Correspond au durcissement habituel de l\'hébergement mutualisé et peut casser un site utilisant l\'extension sockets.',
        ],
    ],

    'errors' => [
        'unsupported_stack' => 'Ce serveur utilise OpenLiteSpeed, qui n\'emploie pas de pools PHP-FPM.',
        'already_isolated' => 'Ce site a déjà son propre pool PHP.',
        'not_isolated' => 'Ce site n\'est pas isolé.',
        'write_failed' => 'La configuration du pool n\'a pas pu être écrite. Rien n\'a été modifié.',
        'config_test_failed' => 'PHP-FPM a rejeté la configuration : elle n\'a pas été appliquée et rien n\'a été rechargé. Le site est servi exactement comme avant.',
        'reload_failed' => 'PHP-FPM n\'a pas pu être rechargé, la configuration précédente a donc été restaurée.',
        'no_sections' => 'Les en-têtes de section sont interdits ici — ils démarreraient un second pool à l\'intérieur de celui-ci.',
        'function_list' => 'Ce doit être une liste de noms de fonctions séparés par des virgules.',
    ],
];
