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

    // Live token health. `unknown` deliberately reads as "we could not
    // check", never as "broken" — the provider was unreachable, not the
    // credential rejected.
    'status' => [
        'valid' => 'Connected',
        'invalid' => 'Token invalid',
        'unknown' => 'Could not check',
    ],

    'fields' => [
        'token' => 'Access token',
        'host' => 'Self-hosted URL',
        'workspace' => 'Workspace',
    ],

    'token_help' => [
        'github' => 'A personal access token with the "repo" scope.',
        'gitlab' => 'A personal access token with the "read_repository" and "read_api" scopes.',
        'bitbucket' => 'A scoped Access Token (workspace, project or repository level). A repository-scoped token will only list that repository.',
    ],

    /*
    | Re-pointing an existing application at a different account.
    */
    'relink' => [
        'repository_unreachable' => 'That account cannot reach this repository. Check the token is still valid and has access to it.',
        'branch_missing' => 'The branch :branch does not exist in this repository.',
    ],

    /*
    | Help for one field of one provider. Keyed per provider because `host`
    | means a GitLab URL and nothing else; a shared key would end up
    | describing two different fields at once.
    */
    'field_help' => [
        'gitlab' => ['host' => 'Only for self-hosted GitLab — the base URL of your instance, for example https://git.example.com. Leave empty for gitlab.com.'],
        'bitbucket' => ['workspace' => 'The workspace ID from your Bitbucket URL: bitbucket.org/<workspace>/<repository>. Not your display name.'],
    ],
];
