<?php

return [
    'operation_failed' => "L'opération de base de données a échoué sur le serveur.",
    'collation_mismatch' => "Le classement sélectionné n'appartient pas au jeu de caractères choisi.",
    'engine_not_installable' => 'Le panneau ne peut pas encore installer ce moteur de base de données. Installez-le vous-même et le panneau le détectera.',

    'engine_install' => [
        'package_not_found' => 'Le paquet de ce moteur n\'est pas disponible dans les sources de paquets de ce serveur.',
        'apt_lock' => 'Une autre opération de paquets est déjà en cours. Attendez la fin puis réessayez.',
        'no_space' => 'Il n\'y a pas assez d\'espace disque libre pour installer ce moteur.',
        'network' => 'Le serveur n\'a pas pu joindre ses sources de paquets. Vérifiez son réseau et son DNS.',
        'dpkg_broken' => 'La base de paquets de ce serveur doit être réparée avant toute autre installation.',
        'port_in_use_by_mysql' => 'MySQL est déjà installé et occupe ce port. Supprimez-le, ou continuez de vous en servir.',
        'port_in_use_by_mariadb' => 'MariaDB est déjà installé et occupe ce port. Supprimez-le, ou continuez de vous en servir.',
        'root_unreachable' => 'Le moteur est installé mais le panneau n\'a pas pu s\'y connecter. Son accès administrateur a été modifié par rapport à la valeur par défaut ; le panneau a besoin de ces informations pour continuer.',
        'grant_failed' => 'Le moteur est installé mais le panneau n\'a pas pu y créer son propre compte.',
        'unknown' => 'L\'installation a échoué. Communiquez la référence au support.',
    ],
];
