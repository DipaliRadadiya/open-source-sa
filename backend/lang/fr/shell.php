<?php

/*
 * Human labels for the login shells a system user can be given. The stored
 * value is the binary path `usermod -s` needs; these are what the panel shows
 * instead, because "/usr/sbin/nologin" does not tell a non-sysadmin that they
 * are turning login off.
 */

return [
    'bash' => [
        'title' => 'Accès shell complet (bash)',
        'description' => 'Le shell Linux standard. L\'utilisateur peut se connecter en SSH et exécuter des commandes.',
    ],
    'sh' => [
        'title' => 'Shell basique (sh)',
        'description' => 'Un shell minimal. Connexion et commandes possibles, avec moins de confort que bash.',
    ],
    'zsh' => [
        'title' => 'Accès shell complet (zsh)',
        'description' => 'Comme bash, avec d\'autres facilités. L\'utilisateur peut se connecter et exécuter des commandes.',
    ],
    'nologin' => [
        'title' => 'Pas de connexion',
        'description' => 'L\'utilisateur possède ses fichiers et fait tourner le site, mais ne peut pas se connecter. Recommandé pour les sites sans besoin d\'accès shell.',
    ],
    'false' => [
        'title' => 'Pas de connexion (ancien)',
        'description' => 'La connexion est refusée immédiatement. Même effet que « Pas de connexion » ; conservé pour les serveurs qui l\'utilisent déjà.',
    ],
];
