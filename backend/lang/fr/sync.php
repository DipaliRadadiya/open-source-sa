<?php

/*
 * Reading a migrated server into the panel.
 *
 * `reasons` are why one discovered thing was skipped or failed. They are
 * shown per row in the run's list, because a sync that reports only what it
 * imported is indistinguishable from one that quietly missed half the box.
 */

return [

    'errors' => [
        'already_running' => 'Une synchronisation est déjà en cours. Attendez la fin avant d\'en lancer une autre.',
    ],

    'reasons' => [
        'unreadable_key' => 'Cette ligne n\'est pas une clé publique lisible par le panneau, elle a donc été laissée telle quelle. Elle peut toujours donner accès — vérifiez-la à la main.',
        'discovery_failed' => 'Impossible de lire depuis le serveur. Rien n\'a été modifié.',
        'adopt_failed' => 'Trouvé sur le serveur, mais le panneau n\'a pas pu créer d\'enregistrement.',
        'requires_system_user' => 'Ignoré car les utilisateurs système ne faisaient pas partie de cette exécution et sont requis d\'abord.',
    ],

];
