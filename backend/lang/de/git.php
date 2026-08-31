<?php

return [
    'providers' => [
        'github' => 'GitHub',
        'gitlab' => 'GitLab',
        'bitbucket' => 'Bitbucket',
    ],

    'status' => [
        'valid' => 'Verbunden',
        'invalid' => 'Token ungültig',
        'unknown' => 'Prüfung nicht möglich',
    ],

    'fields' => [
        'token' => 'Zugriffstoken',
        'host' => 'Selbst gehostete URL',
        'workspace' => 'Workspace',
    ],

    'token_help' => [
        'github' => 'Ein persönliches Zugriffstoken mit dem Scope „repo".',
        'gitlab' => 'Ein persönliches Zugriffstoken mit den Scopes „read_repository" und „read_api".',
        'bitbucket' => 'Ein Access Token mit Gültigkeitsbereich (Workspace, Projekt oder Repository). Ein auf ein Repository begrenztes Token listet nur dieses Repository auf.',
    ],

    /*
    | Re-pointing an existing application at a different account.
    */
    'relink' => [
        'repository_unreachable' => 'Dieses Konto erreicht dieses Repository nicht. Prüfen Sie, ob das Token noch gültig ist und Zugriff hat.',
        'branch_missing' => 'Der Branch :branch existiert in diesem Repository nicht.',
    ],

    /*
    | Help for one field of one provider. Keyed per provider because `host`
    | means a GitLab URL and nothing else; a shared key would end up
    | describing two different fields at once.
    */
    'field_help' => [
        'gitlab' => ['host' => 'Nur für selbst gehostetes GitLab — die Basis-URL Ihrer Instanz, zum Beispiel https://git.example.com. Für gitlab.com leer lassen.'],
        'bitbucket' => ['workspace' => 'Die Workspace-ID aus Ihrer Bitbucket-URL: bitbucket.org/<workspace>/<repository>. Nicht Ihr Anzeigename.'],
    ],
];
