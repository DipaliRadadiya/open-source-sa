<?php

return [
    'busy' => 'Le serveur est occupé par une autre tâche système (une installation ou une mise à jour de paquets est peut-être en cours). Rien n’a été modifié — réessayez dans un instant.',
    'operation_timed_out' => 'Le serveur a mis trop de temps sur cette opération et l\'a interrompue. Rien n\'est cassé : le travail était trop volumineux pour le temps qu\'autorise une seule requête. Au-delà, utilisez une sauvegarde, qui s\'exécute en arrière-plan et n\'a pas cette limite.',
    'stale_lock' => 'Un fichier de verrou résiduel bloque toute la gestion des utilisateurs sur ce serveur. Rien ne l’utilise : une commande interrompue l’a laissé. Lancez `php artisan panel:doctor` pour connaître les fichiers à supprimer.',
    'sudo_denied' => 'L’autorisation sudo de ce serveur est plus ancienne que le panneau qui y tourne : la commande a donc été refusée avant de s’exécuter. Rien n’a été modifié et réessayer n’y changera rien. Lancez `sudo php artisan panel:sudoers` sur le serveur pour réécrire l’autorisation, puis réessayez.',
];
