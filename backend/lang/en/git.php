<?php

/*
 * Labels for the git integration connect form. The API ships these already
 * translated so the frontend renders the provider-specific form from data.
 */

return [
    'providers' => [
        'github' => 'GitHub',
        'gitlab' => 'GitLab',
        'bitbucket' => 'Bitbucket',
    ],

    'fields' => [
        'token' => 'Access token',
        'host' => 'Self-hosted URL',
        'workspace' => 'Workspace',
    ],

    'token_help' => [
        'github' => 'A personal access token with the "repo" scope.',
        'gitlab' => 'A personal access token with the "read_repository" and "read_api" scopes. Leave the URL empty for gitlab.com.',
        'bitbucket' => 'A scoped Access Token (workspace, project or repository level). A repository-scoped token will only list that repository.',
    ],
];
