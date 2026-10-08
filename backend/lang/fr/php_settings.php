<?php

return [
    'presets' => [
        'low' => [
            'title' => 'Faible trafic',
            'description' => 'Quelques processus. Convient à la plupart des petites applications et ménage un petit serveur.',
        ],
        'balanced' => [
            'title' => 'Équilibré',
            'description' => 'Absorbe un trafic normal sans réserver de la mémoire rarement utile.',
        ],
        'high' => [
            'title' => 'Fort trafic',
            'description' => 'Garde des processus prêts. À utiliser quand l’application est réellement chargée : la mémoire est réservée, utilisée ou non.',
        ],
    ],

    'disable_functions_presets' => [
        'safe' => [
            'title' => 'Recommandé',
            'description' => 'Bloque tous les moyens d\'exécuter un programme depuis PHP — ce dont un web shell a besoin, et ce qu\'une application normale ne fait presque jamais.',
        ],
        'strict' => [
            'title' => 'Strict',
            'description' => 'Ajoute l\'inspection des processus, des utilisateurs et des sockets à la liste recommandée. Correspond au durcissement habituel de l\'hébergement mutualisé et peut casser une application utilisant l\'extension sockets.',
        ],
    ],

    'errors' => [
        'missing_account' => 'Le compte Linux sous lequel cette application s\'exécute n\'existe pas sur le serveur : aucun pool PHP n\'a été écrit. PHP-FPM refuse de démarrer avec un pool dont l\'utilisateur est introuvable.',
        'version_not_installed' => 'PHP :version n\'est pas installé sur ce serveur. Installez-le d\'abord, puis sélectionnez-le ici.',
        'version_busy' => 'PHP :version est encore en cours d\'installation ou de suppression. Attendez la fin, puis changez de version.',
        'directive_invalid' => '« :line » n\'est pas un réglage PHP. Un réglage par ligne, par exemple display_errors = Off.',
        'directive_managed' => '« :line » modifie :name, géré par le panneau : il ne peut pas être défini ici. Si cet écran a un champ pour ce réglage, utilisez-le.',
        'directive_extension' => '« :line » charge une extension PHP. Utilisez l\'écran Extensions PHP.',
        'unsupported_stack' => 'Ce serveur utilise OpenLiteSpeed, qui n\'emploie pas de pools PHP-FPM.',
        'not_php_site' => 'Cette application ne sert pas de PHP, il n\'y a donc aucun pool à lui attribuer. Changez d\'abord la façon dont elle est servie.',
        'already_isolated' => 'Cette application a déjà son propre pool PHP.',
        'not_isolated' => 'Cette application n\'est pas isolée.',
        'needs_isolation' => 'Cette application n\'a pas encore son propre pool PHP, ces limites ne pourraient donc pas être appliquées. Attribuez-lui-en un d\'abord, puis enregistrez.',
        'basedir_absolute' => 'Chaque chemin doit être absolu et commencer par /. « :path » ne l\'est pas.',
        'basedir_root' => '« / » autorise tout le système de fichiers : open_basedir serait activé sans rien appliquer. Désactivez plutôt le réglage.',
        'basedir_traversal' => '« :path » n\'est pas autorisé — les chemins ne peuvent pas contenir « .. ».',
        'write_failed' => 'La configuration du pool n\'a pas pu être écrite. Rien n\'a été modifié.',
        'config_test_failed' => 'PHP-FPM a rejeté la configuration : elle n\'a pas été appliquée et rien n\'a été rechargé. L’application est servie exactement comme avant.',
        'reload_failed' => 'PHP-FPM n\'a pas pu être rechargé, la configuration précédente a donc été restaurée.',
        'no_sections' => 'Les en-têtes de section sont interdits ici — ils démarreraient un second pool à l\'intérieur de celui-ci.',
        'function_list' => 'Ce doit être une liste de noms de fonctions séparés par des virgules.',
        'memory_unlimited' => 'La mémoire illimitée (-1) n\'est pas autorisée : une application pourrait utiliser toute la mémoire du serveur et faire tomber toutes les autres applications. Saisissez une limite, par exemple 512M.',
        'memory_over_ram' => 'C\'est plus de mémoire que n\'en a le serveur (:ram). Saisissez une limite plus petite.',
        'memory_too_low' => 'C\'est trop peu de mémoire : PHP lit un nombre sans unité comme des octets, l’application ne pourrait donc pas démarrer. Saisissez au moins 32M, par exemple :suggestion.',
        'post_below_upload' => 'La taille POST maximale (:post) doit être au moins égale à la taille d\'envoi maximale (:upload). Un fichier envoyé passe dans la requête ; sinon, les envois plus gros échouent sans aucune erreur.',
        'prepend_outside_site' => 'Le fichier doit se trouver dans le dossier de cette application (:root/…).',
    ],
];
