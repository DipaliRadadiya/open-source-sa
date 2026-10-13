<?php

return [
    'status' => [
        'pending' => 'En attente de démarrage',
        'running' => 'Mise à jour en cours',
        'completed' => 'Mise à jour',
        'failed' => 'Échec',
    ],
    'reasons' => [
        'unsupported' => 'Ce type d\'application ne peut pas être mis à jour depuis le panneau.',
        'version_unchanged' => 'La mise à jour s\'est exécutée, mais l\'application indique toujours son ancienne version.',
        'step_failed' => 'Une étape de la mise à jour a échoué. La référence renvoie au journal du serveur.',
        'failed' => 'La mise à jour a échoué de manière inattendue. La référence renvoie au journal du serveur.',
        'worker' => 'La mise à jour s\'est arrêtée avant la fin (le processus en arrière-plan s\'est terminé ou a expiré).',
        'abandoned' => 'La mise à jour n\'a jamais démarré et a été annulée.',
        'release_not_found' => 'La nouvelle version est introuvable sur la page de téléchargement du projet.',
        'download_unreachable' => 'La version n\'a pas pu être téléchargée. Vérifiez l\'accès Internet du serveur et réessayez.',
        'out_of_memory' => 'Le serveur a manqué de mémoire pendant la mise à jour.',
        'composer_platform' => 'La version de PHP ou les extensions du site ne répondent pas aux exigences de la nouvelle version.',
        'no_build_tools' => 'Des outils de compilation manquent sur le serveur. Installez-les et réessayez.',
    ],
    'errors' => [
        'running' => 'Une mise à jour de cette application est déjà en cours.',
        'unsupported' => 'Ce type d\'application ne peut pas être mis à jour depuis le panneau.',
        'not_active' => 'Seule une application active et activée peut être mise à jour.',
        'version_unknown' => 'La version installée n\'a pas pu être lue dans les fichiers de l\'application.',
        'lookup_failed' => 'Les versions disponibles n\'ont pas pu être vérifiées pour le moment. Réessayez plus tard.',
        'up_to_date' => 'La version :version est déjà la plus récente de cette version majeure.',
    ],
];
