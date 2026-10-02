<?php

return [
    'not_installed' => ':addon n\'est pas installé sur ce serveur.',
    'licence_required' => ':addon n\'a pas été acheté pour ce serveur.',
    'site_not_registered' => 'Ce site n\'est pas encore enregistré auprès de :addon.',
    'command_failed' => ':addon n\'a pas pu le faire : :message',
    'bad_output' => ':addon a répondu quelque chose que le panneau ne peut pas lire.',
    'timed_out' => ':addon n\'a pas terminé à temps.',
    'no_system_user' => 'Ce site n\'a pas d\'utilisateur système.',
    'run_failed' => 'La commande de l\'extension a échoué de façon inattendue.',
    'unregistered' => 'Site désenregistré.',
    'option_required' => ':option est requis pour ce rapport.',
    'redis_unavailable' => 'Redis ne tourne pas sur ce serveur, Object Cache Pro ne peut donc pas être configuré.',
    'redis_too_old' => 'Object Cache Pro nécessite Redis 6 ou plus récent pour un accès propre à chaque site ; ce serveur a Redis :version.',
    'redis_no_password' => 'Définissez d\'abord un mot de passe Redis : sans lui, n\'importe quel site pourrait lire le cache des autres.',
    'redis_failed' => 'Redis n\'a pas pu créer l\'accès du site.',
    'object_cache_not_enabled' => 'Object Cache Pro n\'est pas activé sur ce site.',
];
