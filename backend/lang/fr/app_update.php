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
        'download_timeout' => 'Le téléchargement a pris trop de temps et a été interrompu. Vérifiez l’accès à Internet du serveur, puis réessayez.',
        'download_http_error' => 'L’hôte de téléchargement a répondu par une erreur au lieu de la version. Réessayez dans quelques minutes.',
        'download_tls' => 'Une connexion sécurisée avec l’hôte de téléchargement n’a pas pu être établie. Vérifiez la date et l’heure du serveur ainsi que ses certificats d’autorité, puis réessayez.',
        'download_interrupted' => 'La connexion a été coupée pendant le téléchargement, la version est donc incomplète. Réessayez.',
        'archive_invalid' => 'La version téléchargée n’a pas pu être décompressée : elle est incomplète ou n’est pas l’archive attendue. Réessayez.',
        'out_of_memory' => 'Le serveur a manqué de mémoire pendant la mise à jour.',
        'composer_platform' => 'La version de PHP ou les extensions du site ne répondent pas aux exigences de la nouvelle version.',
        'no_build_tools' => 'Des outils de compilation manquent sur le serveur. Installez-les et réessayez.',
        'node_unsupported' => 'La nouvelle version nécessite une version de Node.js que ce site n\'utilise pas. Changez la version de Node.js du site, puis mettez à jour.',
        'app_not_ready' => 'La nouvelle version n\'a pas démarré ; la version précédente a été remise en place.',
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
