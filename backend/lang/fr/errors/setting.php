<?php

return [
    'operation_failed' => 'La modification des paramètres a échoué sur le serveur.',
    'group_unavailable' => "Ce groupe de paramètres n'est pas disponible sur ce serveur.",
    'security_updates_unavailable' => 'unattended-upgrades n\'est pas installé sur ce serveur, le panneau n\'a donc rien à exécuter. Installez le paquet unattended-upgrades, puis réessayez.',
    'security_updates_in_progress' => 'Une mise à jour de sécurité est déjà en cours.',
    'no_ssh_key' => "Ajoutez une clé SSH avant de désactiver l'authentification par mot de passe, sinon vous risquez de vous verrouiller.",
    'redis_credential_unusable' => 'Le panneau ne peut pas joindre Redis avec le mot de passe qu\'il a enregistré et ne peut donc pas le modifier. Redis fonctionne mais rejette les identifiants du panneau : corrigez REDIS_PASSWORD dans le .env du panneau avec le mot de passe réellement exigé par Redis, puis réessayez.',
    'env_not_writable' => 'Le panneau ne peut pas écrire son propre fichier .env, le nouveau mot de passe Redis n\'a donc pas pu être enregistré. Corrigez d\'abord les permissions du fichier, sinon le panneau perdrait l\'accès à Redis.',
    'swap_in_use' => 'Le fichier d’échange est utilisé et n’a pas pu être désactivé. Le serveur n’a pas assez de mémoire libre pour récupérer les données échangées — libérez de la mémoire, puis réessayez.',
    'swap_below_minimum' => 'Le swap ne peut pas descendre sous :minimum Mo sur ce serveur. Lors d’une mise à jour, le panneau compile son propre frontend, ce qui demande :required Mo de mémoire et de swap réunis — en dessous, la mise à jour est interrompue en cours de route. Ajoutez de la RAM, ou définissez SERVER_SWAP_ENFORCE_MINIMUM=false si vous gérez vous-même le swap de ce serveur.',
];
