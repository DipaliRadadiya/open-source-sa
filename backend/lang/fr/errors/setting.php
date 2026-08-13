<?php

return [
    'operation_failed' => 'La modification des paramètres a échoué sur le serveur.',
    'group_unavailable' => "Ce groupe de paramètres n'est pas disponible sur ce serveur.",
    'no_ssh_key' => "Ajoutez une clé SSH avant de désactiver l'authentification par mot de passe, sinon vous risquez de vous verrouiller.",
    'env_not_writable' => 'Le panneau ne peut pas écrire son propre fichier .env, le nouveau mot de passe Redis n\'a donc pas pu être enregistré. Corrigez d\'abord les permissions du fichier, sinon le panneau perdrait l\'accès à Redis.',
    'swap_in_use' => 'Le fichier d’échange est utilisé et n’a pas pu être désactivé. Le serveur n’a pas assez de mémoire libre pour récupérer les données échangées — libérez de la mémoire, puis réessayez.',
];
