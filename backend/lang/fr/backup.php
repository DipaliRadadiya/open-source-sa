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
        'restore_unverified' => 'Cette sauvegarde n\'a jamais été vérifiée, elle ne peut donc pas être restaurée.',
        'restore_no_application' => 'L\'application de cette sauvegarde n\'existe plus.',
        'restore_confirm' => 'Saisissez exactement le domaine de l\'application pour confirmer la restauration.',
        'restore_already_running' => 'Une restauration est déjà en cours pour cette application.',
        'restore_no_database' => 'Cette sauvegarde ne contient aucune base de données.',
        'restore_no_files' => 'Cette sauvegarde ne contient aucun fichier.',
        'not_configured' => 'Les sauvegardes ne sont pas encore configurées pour cette application.',
        'already_running' => 'Une sauvegarde est déjà en cours pour cette application.',
        'dump_database' => 'La base de données n’a pas pu être exportée, rien n’a donc été envoyé.',
        'archive_files' => 'L’archive n’a pas pu être créée — le serveur manque généralement d’espace disque.',
        'upload_artifact' => 'L’archive n’a pas pu être envoyée. Vérifiez que la destination de stockage accepte toujours les écritures.',
        'verify_artifact' => 'L’envoi ne correspond pas à ce qui a été transmis ; cette sauvegarde n’est pas fiable. Aucune ancienne sauvegarde n’a été supprimée.',
        'unknown' => 'La sauvegarde a échoué pour une raison inconnue.',
        'prune_old_backups' => 'Les anciennes sauvegardes n’ont pas pu être supprimées. La nouvelle est intacte ; le stockage conserve peut-être plus de copies que prévu.',
    ],

    'restore_status' => [
        'pending' => 'En file d\'attente',
        'running' => 'Restauration en cours',
        'succeeded' => 'Restauré',
        'failed' => 'Échec de la restauration',
    ],

    'restore_steps' => [
        'download_artifact' => 'Téléchargement de la sauvegarde',
        'verify_download' => 'Vérification de l\'intégrité de la sauvegarde',
        'safety_backup' => 'Sauvegarde de l\'état actuel d\'abord',
        'extract_archive' => 'Décompression de la sauvegarde',
        'restore_database' => 'Restauration de la base de données',
        'swap_files' => 'Mise en place des fichiers',
        'restart_process' => 'Démarrage de l\'application',
    ],

    'restore_errors' => [
        'download_artifact' => 'La sauvegarde n\'a pas pu être téléchargée. Rien n\'a été modifié sur le serveur.',
        'verify_download' => 'La sauvegarde téléchargée est incomplète ou corrompue et n\'a pas été utilisée. Rien n\'a été modifié sur le serveur.',
        'safety_backup' => 'L\'état actuel n\'a pas pu être sauvegardé, la restauration a donc été arrêtée. Rien n\'a été écrasé.',
        'extract_archive' => 'La sauvegarde n\'a pas pu être décompressée. Rien n\'a été modifié sur le serveur.',
        'restore_database' => 'La base de données n\'a pas pu être restaurée. La sauvegarde prise au préalable contient l\'état précédent.',
        'swap_files' => 'Les fichiers n\'ont pas pu être mis en place. Le répertoire précédent du site a été restauré.',
        'restart_process' => 'Les fichiers et la base de données ont été restaurés mais l\'application n\'a pas démarré. Consultez ses journaux.',
        'missing_backup' => 'La sauvegarde a été supprimée avant que la restauration puisse commencer.',
        'crashed' => 'La restauration s\'est interrompue de manière inattendue. Vérifiez la sauvegarde avant de réessayer.',
        'unknown' => 'La restauration a échoué pour une raison inconnue.',
    ],
];
