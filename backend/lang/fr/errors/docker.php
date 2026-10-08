<?php

return [
    'not_a_docker_server' => 'Ce serveur n\'héberge pas de conteneurs : il n\'a donc ni réseaux ni volumes Docker.',
    'invalid_name' => 'Un nom peut contenir lettres, chiffres, points, tirets et tirets bas, et doit commencer par une lettre ou un chiffre.',
    'network_exists' => 'Un réseau nommé :name existe déjà.',
    'volume_exists' => 'Un volume nommé :name existe déjà.',
    'network_built_in' => ':name est l\'un des réseaux propres à Docker. Docker le recrée au redémarrage, et le supprimer casserait tous les conteneurs de ce serveur.',
    'network_in_use' => 'Le réseau :name a encore des conteneurs connectés : :containers. Arrêtez-les ou détachez-les d\'abord.',
    'network_used_by_sites' => 'Ces sites sont configurés pour rejoindre le réseau :name : :sites. Changez d\'abord leur réseau — le supprimer maintenant les empêcherait de démarrer.',
    'volume_in_use' => 'Le volume :name est encore utilisé par des conteneurs (:count). Arrêtez-les d\'abord : supprimer un volume en cours d\'utilisation efface des données qu\'un processus écrit encore.',
    'volume_in_use_by' => 'Le volume :name est encore utilisé par :containers. Arrêtez-les d\'abord — supprimer un volume en cours d\'utilisation efface des données en train d\'être écrites.',
    'volume_used_by_sites' => 'Ces sites montent le volume :name : :sites. Retirez d\'abord le montage — le supprimer maintenant détruit les données qu\'ils y conservent.',
    'network_create_failed' => 'Le réseau n\'a pas pu être créé. Référence :reference.',
    'network_remove_failed' => 'Le réseau n\'a pas pu être supprimé. Référence :reference.',
    'volume_create_failed' => 'Le volume n\'a pas pu être créé. Référence :reference.',
    'volume_remove_failed' => 'Le volume n\'a pas pu être supprimé. Référence :reference.',
    'registry_deleted' => 'L’identifiant de registre a été supprimé. Les sites qui l’utilisaient récupéreront désormais les images de façon anonyme.',
    'registry_credential_unwritable' => 'L’identifiant de registre n’a pas pu être écrit sur le disque, Docker n’a donc jamais été interrogé. Référence :reference.',
    'database_start_failed' => 'La base de données n’a pas pu démarrer (:step). Rien n’a été laissé derrière : aucune entrée, aucun conteneur, aucun port réservé.',
    'database_version_unknown' => 'Ce moteur n’a pas de version :version. Choisissez-en une proposée par le panneau pour :engine.',
    'database_deleted' => 'La base de données a été supprimée.',
];
