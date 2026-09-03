<?php

return [
    'operation_failed' => 'La modification des paramètres a échoué sur le serveur.',
    'group_unavailable' => "Ce groupe de paramètres n'est pas disponible sur ce serveur.",
    'no_ssh_key' => "Ajoutez une clé SSH avant de désactiver l'authentification par mot de passe, sinon vous risquez de vous verrouiller.",
    'redis_credential_unusable' => 'Le panneau ne peut pas joindre Redis avec le mot de passe qu\'il a enregistré et ne peut donc pas le modifier. Redis fonctionne mais rejette les identifiants du panneau : corrigez REDIS_PASSWORD dans le .env du panneau avec le mot de passe réellement exigé par Redis, puis réessayez.',
    'env_not_writable' => 'Le panneau ne peut pas écrire son propre fichier .env, le nouveau mot de passe Redis n\'a donc pas pu être enregistré. Corrigez d\'abord les permissions du fichier, sinon le panneau perdrait l\'accès à Redis.',
    'swap_in_use' => 'Le fichier d’échange est utilisé et n’a pas pu être désactivé. Le serveur n’a pas assez de mémoire libre pour récupérer les données échangées — libérez de la mémoire, puis réessayez.',
    'database_unreachable' => 'Le panneau ne peut pas se connecter au serveur de base de données, cette modification n\'a donc pas été appliquée.',
    'database_absent' => 'Aucun serveur MySQL ou MariaDB n\'est installé sur cette machine.',
];
