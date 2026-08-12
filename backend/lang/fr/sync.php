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
        'panel_infrastructure' => 'Il s\'agit du panneau lui-même, pas d\'un site qu\'il peut héberger. Laissé de côté volontairement.',
        'outside_panel_layout' => 'Ce site n\'est pas organisé comme le panneau gère les sites ; il ne peut pas être adopté sans déplacer ses fichiers. Il continue d\'être servi — rien n\'a changé.',
        'vhost_unreadable' => 'La configuration du serveur web de ce site n\'a pas pu être lue ; il a été laissé tel quel.',
        'vhost_unparsed' => 'Ce site est servi, mais sa configuration n\'a pas une forme que le panneau sait lire. Adoptez-le à la main ou vérifiez le fichier.',
        'owner_not_tracked' => 'Le compte Linux propriétaire de ce site n\'est pas géré par le panneau. Synchronisez d\'abord les utilisateurs système.',
        'unreadable_key' => 'Cette ligne n\'est pas une clé publique lisible par le panneau, elle a donc été laissée telle quelle. Elle peut toujours donner accès — vérifiez-la à la main.',
        'discovery_failed' => 'Impossible de lire depuis le serveur. Rien n\'a été modifié.',
        'adopt_failed' => 'Trouvé sur le serveur, mais le panneau n\'a pas pu créer d\'enregistrement.',
        'requires_system_user' => 'Ignoré car les utilisateurs système ne faisaient pas partie de cette exécution et sont requis d\'abord.',
    ],

];
