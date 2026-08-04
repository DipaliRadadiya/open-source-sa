<?php

return [
    'checks' => [
        'privilege' => 'Commandes privilégiées',
        'services' => 'Services',
        'writable_paths' => 'Chemins accessibles en écriture',
        'database' => 'Base de données',
        'health_endpoint' => 'Point de terminaison de santé',
    ],
    'fixes' => [
        'privilege' => 'Le panneau ne peut pas exécuter de commandes en root. Vérifiez que /etc/sudoers.d/ contient l’autorisation et que le fichier passe visudo -c.',
        'privilege_disabled' => 'L’élévation de privilèges est désactivée alors que le panneau n’est pas root. Retirez SERVER_OPS_SUDO=false de .env.',
        'services_missing' => 'Une unité attendue n’existe pas. Définissez PANEL_FRONTEND_SERVICE et PANEL_QUEUE_SERVICE dans .env avec les noms réels.',
        'services_down' => 'Démarrez-les avec systemctl start, puis consultez journalctl -u <unité>.',
        'writable_paths' => 'Donnez la propriété au compte du panneau : chown -R <utilisateur> sur les chemins listés.',
        'database_unreachable' => 'Vérifiez les réglages DB_ dans .env et que le service de base de données tourne.',
        'database_pending' => 'Exécutez php artisan migrate --force. Le code a été mis à jour sans appliquer ses changements de schéma.',
        'health_unreachable' => 'Vérifiez qu’APP_URL dans .env correspond à l’adresse du panneau et que le serveur web et php-fpm tournent.',
        'health_version_mismatch' => 'Le code exécuté et la version servie diffèrent. Videz les caches avec php artisan optimize:clear et rechargez php-fpm.',
    ],
];
