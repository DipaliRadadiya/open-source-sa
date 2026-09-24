<?php

return [
    'not_a_docker_server' => 'Ce serveur n\'héberge pas de conteneurs : il n\'a donc ni réseaux ni volumes Docker.',
    'invalid_name' => 'Un nom peut contenir lettres, chiffres, points, tirets et tirets bas, et doit commencer par une lettre ou un chiffre.',
    'network_exists' => 'Un réseau nommé :name existe déjà.',
    'volume_exists' => 'Un volume nommé :name existe déjà.',
    'network_built_in' => ':name est l\'un des réseaux propres à Docker. Docker le recrée au redémarrage, et le supprimer casserait tous les conteneurs de ce serveur.',
    'network_in_use' => 'Le réseau :name a encore des conteneurs connectés : :containers. Arrêtez-les ou détachez-les d\'abord.',
    'network_used_by_sites' => 'Ces sites sont configurés pour rejoindre le réseau :name : :sites. Changez d\'abord leur réseau — le supprimer maintenant les empêcherait de démarrer.',
    'volume_in_use' => 'Le volume :name est encore utilisé par :count conteneur(s). Arrêtez-les d\'abord : supprimer un volume en cours d\'utilisation efface des données qu\'un processus écrit encore.',
    'network_create_failed' => 'Le réseau n\'a pas pu être créé. Référence :reference.',
    'network_remove_failed' => 'Le réseau n\'a pas pu être supprimé. Référence :reference.',
    'volume_create_failed' => 'Le volume n\'a pas pu être créé. Référence :reference.',
    'volume_remove_failed' => 'Le volume n\'a pas pu être supprimé. Référence :reference.',
];
