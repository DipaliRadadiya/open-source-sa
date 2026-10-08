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
        'firewall_direction_unsupported' => 'C\'est une règle sortante. Le panneau ne gère que les règles entrantes ; l\'enregistrer ici l\'appliquerait dans le mauvais sens.',
        'firewall_action_unsupported' => 'Cette règle limite ou rejette au lieu d\'autoriser ou de refuser. Le panneau n\'a pas d\'équivalent, et l\'enregistrer comme un simple allow ou deny décrirait mal ce que fait le serveur.',
        'firewall_app_profile' => 'Cette règle utilise un profil d\'application plutôt qu\'un port. Les ports derrière peuvent changer lors d\'une mise à jour du paquet ; importer les numéros d\'aujourd\'hui serait un instantané se faisant passer pour la règle.',
        'panel_infrastructure' => 'Il s\'agit du panneau lui-même, pas d\'une application qu\'il peut héberger. Laissé de côté volontairement.',
        'outside_panel_layout' => 'Cette application n\'est pas organisée comme le panneau gère les applications ; elle ne peut pas être adoptée sans déplacer ses fichiers. Elle continue d\'être servie — rien n\'a changé.',
        'folder_taken' => 'Une autre application du panneau utilise déjà un dossier portant ce nom. Les noms de dossier doivent être uniques, cette application a donc été laissée telle quelle. Elle reste en ligne.',
        'folder_name_unusable' => 'Le nom du dossier de cette application contient des caractères que le panneau ne peut pas utiliser dans des noms de fichier, elle a donc été laissée telle quelle. Elle reste en ligne.',
        'vhost_unreadable' => 'La configuration du serveur web de cette application n\'a pas pu être lue ; elle a été laissée telle quelle.',
        'vhost_unparsed' => 'Cette application est servie, mais sa configuration n\'a pas une forme que le panneau sait lire. Adoptez-la à la main ou vérifiez le fichier.',
        'owner_not_tracked' => 'Le compte Linux propriétaire de cette application n\'est pas géré par le panneau. Synchronisez d\'abord les utilisateurs système.',
        'unreadable_key' => 'Cette ligne n\'est pas une clé publique lisible par le panneau, elle a donc été laissée telle quelle. Elle peut toujours donner accès — vérifiez-la à la main.',
        'discovery_failed' => 'Impossible de lire depuis le serveur. Rien n\'a été modifié.',
        'adopt_failed' => 'Trouvé sur le serveur, mais le panneau n\'a pas pu créer d\'enregistrement.',
        'requires_system_user' => 'Ignoré car les utilisateurs système ne faisaient pas partie de cette exécution et sont requis d\'abord.',
        'after_sites_adopted' => 'Cet aperçu a trouvé de nouvelles applications. Leurs workers, certificats SSL et réglages PHP s\'affichent une fois les applications synchronisées : l\'application de la synchronisation adopte d\'abord les applications, puis lit ces éléments.',
    ],

];
