<?php

/*
 * Copy for the Storage destinations integration — S3-compatible remote
 * targets that backups are uploaded to. The keys in this file render the
 * connect form, the row labels and the outcome of the test-connection probe.
 */

return [
    'drivers' => [
        's3' => 'Compatible S3',
    ],

    'fields' => [
        'name' => 'Nom affiché',
        'endpoint' => 'URL du point de terminaison',
        'region' => 'Région',
        'bucket' => 'Bucket',
        'prefix' => 'Préfixe de clé (facultatif)',
        'access_key' => 'Clé d’accès',
        'secret_key' => 'Clé secrète',
    ],

    'placeholders' => [
        'endpoint' => 'https://s3.amazonaws.com',
        'region' => 'us-east-1',
        'prefix' => 'backups/production/',
    ],

    'help' => [
        'name' => 'Un libellé court pour distinguer les destinations dans la liste des intégrations.',
        'endpoint' => 'Laissez la valeur par défaut pour AWS. Renseignez-la pour MinIO, R2, Backblaze B2, Wasabi, etc.',
        'region' => 'Région où se trouve le bucket (requise uniquement pour AWS).',
        'prefix' => 'Préfixe de chemin facultatif dans le bucket (sans barre oblique initiale).',
        'access_key' => 'En écriture seule — jamais renvoyée par l’API.',
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
    ],

    'delete' => [
        'in_use' => 'Impossible de supprimer :name — cette destination est encore utilisée par :applications. Supprimez ou redirigez ces cibles de sauvegarde d’abord.',
        'and_more' => ':count de plus',
    ],
];
