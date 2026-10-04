<?php

return [
    'not_found' => 'Ce processus n\'est plus en cours d\'exécution.',
    'protected' => 'Ce processus appartient à un service protégé et ne peut pas être arrêté ici.',
    'database' => 'Il s\'agit d\'un serveur de base de données. L\'arrêter mettrait hors ligne la base de données de tous les sites ; il ne peut donc pas être arrêté ici. Pour le redémarrer, utilisez l\'écran Services.',
    'kernel_thread' => 'Les threads du noyau ne peuvent pas être arrêtés.',
    'self' => 'Le panneau ne peut pas arrêter son propre processus.',
    'kill_failed' => 'Impossible d\'arrêter le processus.',
    'still_running' => 'Le processus est toujours en cours d\'exécution. Il est peut-être encore en train de s\'arrêter, ou il ignore la demande. Utilisez Forcer l\'arrêt pour le terminer maintenant.',
    'still_running_after_kill' => 'Le processus est toujours en cours d\'exécution après Forcer l\'arrêt. Il est probablement bloqué en attente d\'un disque ou d\'un partage réseau, et aucun signal ne peut le terminer avant la fin de cette attente.',
];
