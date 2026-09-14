<?php

/*
 * Copy for the Storage destinations integration — S3-compatible remote
 * targets that backups are uploaded to. The keys in this file render the
 * connect form, the row labels and the outcome of the test-connection probe.
 */

return [
    'drivers' => [
        's3' => 'Compatible S3',
        'ftp' => 'FTP',
        'sftp' => 'SFTP',
    ],

    'fields' => [
        'name' => 'Nom affiché',
        'endpoint' => 'URL du point de terminaison',
        'region' => 'Région',
        'bucket' => 'Bucket',
        'prefix' => 'Préfixe de clé (facultatif)',
        'access_key' => 'Clé d’accès',
        'secret_key' => 'Clé secrète',
        'host' => 'Hôte',
        'port' => 'Port',
        'username' => 'Nom d’utilisateur',
        'password' => 'Mot de passe',
        'root' => 'Répertoire distant',
        'ssl' => 'Utiliser TLS (FTPS)',
        'passive' => 'Mode passif',
        'private_key' => 'Clé privée',
        'passphrase' => 'Phrase secrète de la clé',
        'host_fingerprint' => 'Empreinte de la clé d’hôte',
    ],

    'placeholders' => [
        'endpoint' => 'https://s3.amazonaws.com',
        'region' => 'us-east-1',
        'prefix' => 'backups/production/',
        'host' => 'backup.exemple.fr',
        'root' => 'backups/',
    ],

    'help' => [
        'name' => 'Un libellé court pour distinguer les destinations dans la liste des intégrations.',
        'endpoint' => 'Laissez la valeur par défaut pour AWS. Renseignez-la pour MinIO, R2, Backblaze B2, Wasabi, etc.',
        'region' => 'Région où se trouve le bucket (requise uniquement pour AWS).',
        'prefix' => 'Préfixe de chemin facultatif dans le bucket (sans barre oblique initiale).',
        'access_key' => 'En écriture seule — jamais renvoyée par l’API.',
        'host' => 'Nom d’hôte ou adresse IP du serveur qui conservera les sauvegardes.',
        'port' => 'Laisser vide pour utiliser la valeur par défaut.',
        'root' => 'Répertoire du serveur où écrire. Laisser vide pour rester là où la connexion aboutit.',
        'ssl' => 'Fortement recommandé. Sans cela, le mot de passe et toute la sauvegarde circulent en clair.',
        'passive' => 'À laisser activé sauf si le serveur exige le contraire.',
        'private_key' => 'Collez la clé privée entière. Utilisée à la place d’un mot de passe.',
        'passphrase' => 'Uniquement si la clé privée est elle-même chiffrée.',
        'host_fingerprint' => 'Enregistrée lors de la première connexion, puis imposée. À comparer avec la clé présente sur le serveur.',
        'plain_ftp_warning' => 'TLS est désactivé. Le mot de passe et chaque sauvegarde seront envoyés en clair.',
    ],

    'status' => [
        'connected' => 'Connecté',
        'never_tested' => 'Pas encore testé',
        'failed' => 'Dernier test échoué',
    ],

    'test' => [
        'success' => 'Connexion réussie.',
        'failure' => 'Impossible de se connecter à la destination.',
        'invalid_credentials' => 'La destination a rejeté les identifiants.',
        'unreachable' => 'Le point de terminaison de la destination est injoignable.',
        'mismatch' => 'La destination a relu des octets différents de ceux écrits.',
        'forbidden_host' => 'Cette adresse de point de terminaison n’est pas autorisée.',
        'invalid_endpoint' => 'Saisissez une URL de point de terminaison https:// valide pour le bucket.',
        'invalid_host' => 'Saisissez un nom d’hôte ou une adresse IP valide.',
        'host_key_mismatch' => 'Le serveur a présenté une clé d’hôte différente de celle enregistrée. La connexion a été interrompue.',
        'invalid_private_key' => 'La clé privée n’a pas pu être lue. Vérifiez qu’elle a été collée en entier.',
        'root_missing' => "Le dossier de destination n'existe pas sur le serveur. Créez-le ou corrigez le chemin du dossier.",
    ],

    'delete' => [
        'in_use' => 'Impossible de supprimer :name — cette destination est encore utilisée par :applications. Supprimez ou redirigez ces cibles de sauvegarde d’abord.',
        'and_more' => ':count de plus',
    ],

    'validation' => [
        'sftp_auth_required' => 'Indiquez soit un mot de passe, soit une clé privée.',
    ],
];
