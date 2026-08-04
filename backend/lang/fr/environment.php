<?php

return [
    'frameworks' => [
        'laravel' => 'Laravel',
        'craft' => 'Craft CMS',
        'statamic' => 'Statamic',
        'nextjs' => 'Next.js',
        'nuxt' => 'Nuxt',
        'node' => 'Node.js',
        'unknown' => 'Inconnu',
    ],

    'checks' => [
        'app_debug_on' => [
            'title' => 'Le mode débogage est activé',
            'detail' => 'Quiconque déclenche une erreur voit la trace complète, y compris les identifiants de base de données. Mettez APP_DEBUG à false sur un site en production.',
        ],
        'app_env_local' => [
            'title' => 'Le site tourne dans un environnement de développement',
            'detail' => 'APP_ENV a une valeur de développement, ce qui change le comportement des erreurs, du cache et des e-mails. Mettez-le à production sur un site en ligne.',
        ],
        'app_key_missing' => [
            'title' => 'APP_KEY est absente',
            'detail' => 'Sans elle l\'application ne peut pas déchiffrer les sessions ni les cookies, et refusera généralement de démarrer.',
        ],
        'next_public_secret' => [
            'title' => '":key" est envoyée à chaque visiteur',
            'detail' => 'Tout ce qui commence par NEXT_PUBLIC_ est intégré au bundle du navigateur. Un secret ici est déjà public.',
        ],
        'duplicate_key' => [
            'title' => '":key" est définie plusieurs fois',
            'detail' => 'Seule la dernière s\'applique, la valeur affichée n\'est donc peut-être pas celle utilisée. Ligne :line.',
        ],
        'syntax_no_equals' => [
            'title' => 'La ligne :line ne contient pas de "="',
            'detail' => 'Chaque ligne doit être CLÉ=valeur, un commentaire, ou vide.',
        ],
        'syntax_bad_key' => [
            'title' => 'La ligne :line n\'est pas une variable valide',
            'detail' => 'Un nom doit commencer par une lettre ou un tiret bas et ne contenir que des lettres, chiffres et tirets bas.',
        ],
        'syntax_unbalanced_quote' => [
            'title' => '":key" a un guillemet non fermé',
            'detail' => 'La valeur de la ligne :line ouvre un guillemet jamais fermé et déborde sur les lignes suivantes.',
        ],
        'syntax_export' => [
            'title' => '":key" utilise "export"',
            'detail' => 'Cette application lit son environnement via systemd, qui rejette le mot-clé export et ne démarrera pas. Supprimez-le. Ligne :line.',
        ],
    ],

];
