<?php

/*
 * Everything the application create form displays. Shipped already translated
 * so the frontend renders one generic form and never holds a label list.
 */

return [
    'types' => [
        'wordpress' => ['title' => 'WordPress', 'tagline' => 'Blog and website builder'],
        'git' => ['title' => 'From Git repo', 'tagline' => 'Deploy your own code from GitHub, GitLab or Bitbucket'],
        'php' => ['title' => 'Blank PHP site', 'tagline' => 'An empty site — upload your own files'],
        'static' => ['title' => 'Static site', 'tagline' => 'Plain HTML, CSS and JavaScript'],
    ],

    'status' => [
        'pending' => 'Not deployed yet',
        'provisioning' => 'Setting up…',
        'active' => 'Running',
        'failed' => 'Setup failed',
    ],

    'unavailable' => [
        'php' => 'This server does not have PHP installed.',
        'node' => 'This server does not have Node.js installed.',
    ],

    'git_source' => [
        'account' => 'From a connected account',
        'public_url' => 'Paste a public repository URL',
    ],

    'fields' => [
        'name' => 'Name',
        'domain' => 'Domain',
        'system_user_id' => 'System user',
        'php_version' => 'PHP version',
        'node_version' => 'Node.js version',
        'app_port' => 'App port',
        'web_root' => 'Web root',
        'build_command' => 'Build command',
        'start_command' => 'Start command',
        'git_source' => 'Source',
        'git_account_id' => 'Git account',
        'repository' => 'Repository',
        'repository_url' => 'Repository URL',
        'branch' => 'Branch',
        'site_title' => 'Site title',
        'admin_user' => 'Admin username',
        'admin_email' => 'Admin email',
        'admin_password' => 'Admin password',
        'site_language' => 'Site language',
        'table_prefix' => 'Table prefix',
    ],

    'help' => [
        'repository_url' => 'A public repository — no account needed. Must be an https:// address.',
        'build_command' => 'Run after the code is fetched, e.g. composer install --no-dev',
    ],
];
