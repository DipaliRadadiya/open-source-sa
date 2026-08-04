<?php

return [
    'steps' => [
        'dump_database' => 'Export de la base de données',
        'archive_files' => 'Création de l’archive',
        'upload_artifact' => 'Envoi vers le stockage',
        'verify_artifact' => 'Vérification de l’envoi',
        'prune_old_backups' => 'Suppression des anciennes sauvegardes',
        'rollback' => 'Nettoyage',
    ],
    'status' => [
        'pending' => 'En file',
        'running' => 'Sauvegarde en cours',
        'verifying' => 'Vérification',
        'verified' => 'Terminée',
        'failed' => 'Échouée',
    ],
    'type' => [
        'filesystem' => 'Fichiers',
        'database' => 'Base de données',
        'full' => 'Fichiers et base de données',
    ],
    'frequency' => [
        'manual' => 'Manuel uniquement',
        'daily' => 'Quotidienne',
        'weekly' => 'Hebdomadaire',
        'monthly' => 'Mensuelle',
    ],
    'errors' => [
        'not_configured' => 'Les sauvegardes ne sont pas encore configurées pour cette application.',
        'already_running' => 'Une sauvegarde est déjà en cours pour cette application.',
        'dump_database' => 'La base de données n’a pas pu être exportée, rien n’a donc été envoyé.',
        'archive_files' => 'L’archive n’a pas pu être créée — le serveur manque généralement d’espace disque.',
        'upload_artifact' => 'L’archive n’a pas pu être envoyée. Vérifiez que la destination de stockage accepte toujours les écritures.',
        'verify_artifact' => 'L’envoi ne correspond pas à ce qui a été transmis ; cette sauvegarde n’est pas fiable. Aucune ancienne sauvegarde n’a été supprimée.',
        'unknown' => 'La sauvegarde a échoué pour une raison inconnue.',
        'prune_old_backups' => 'Les anciennes sauvegardes n’ont pas pu être supprimées. La nouvelle est intacte ; le stockage conserve peut-être plus de copies que prévu.',
    ],
];
