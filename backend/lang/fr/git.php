<?php

return [
    'providers' => [
        'github' => 'GitHub',
        'gitlab' => 'GitLab',
        'bitbucket' => 'Bitbucket',
    ],

    'status' => [
        'valid' => 'Connecté',
        'invalid' => 'Jeton invalide',
        'unknown' => 'Vérification impossible',
    ],

    'fields' => [
        'token' => 'Jeton d\'accès',
        'host' => 'URL auto-hébergée',
        'workspace' => 'Espace de travail',
    ],

    'token_help' => [
        'github' => 'Un jeton d\'accès personnel avec la portée « repo ».',
        'gitlab' => 'Un jeton d\'accès personnel avec les portées « read_repository » et « read_api ». Laissez l\'URL vide pour gitlab.com.',
        'bitbucket' => 'Un jeton d\'accès à portée limitée (espace de travail, projet ou dépôt). Un jeton limité à un dépôt n\'affichera que ce dépôt.',
    ],

    /*
    | Re-pointing an existing application at a different account.
    */
    'relink' => [
        'repository_unreachable' => 'Ce compte ne peut pas accéder à ce dépôt. Vérifiez que le jeton est toujours valide et y a accès.',
        'branch_missing' => 'La branche :branch n\'existe pas dans ce dépôt.',
    ],

    /*
    | Help for one field of one provider. Keyed per provider because `host`
    | means a GitLab URL and nothing else; a shared key would end up
    | describing two different fields at once.
    */
    'field_help' => [
        'gitlab' => ['host' => 'Uniquement pour GitLab auto-hébergé — l\'URL de base de votre instance, par exemple https://git.example.com. Laissez vide pour gitlab.com.'],
        'bitbucket' => ['workspace' => 'L\'identifiant d\'espace de travail figurant dans votre URL Bitbucket : bitbucket.org/<workspace>/<dépôt>. Ce n\'est pas votre nom affiché.'],
    ],
];
