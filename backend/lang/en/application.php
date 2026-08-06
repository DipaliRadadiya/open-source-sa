<?php

/*
 * Everything the application create form displays. Shipped already translated
 * so the frontend renders one generic form and never holds a label list.
 */

return [
    // What a name attached to an application does. Shown as the badge
    // beside each domain, so it has to read as a noun, not a sentence.
    'domain_type' => [
        'primary' => 'Primary',
        'alias' => 'Alias',
        'redirect' => 'Redirect',
    ],

    'types' => [
        'wordpress' => ['title' => 'WordPress', 'tagline' => 'Blog and website builder'],
        'phpmyadmin' => ['title' => 'phpMyAdmin', 'tagline' => 'Manage your databases in the browser'],
        'uptimekuma' => ['title' => 'Uptime Kuma', 'tagline' => 'Uptime monitoring and status pages'],
        'n8n' => ['title' => 'n8n', 'tagline' => 'Workflow automation (fair-code licence)'],
        'nodered' => ['title' => 'Node-RED', 'tagline' => 'Wire up devices, APIs and services'],
        'nodebb' => ['title' => 'NodeBB', 'tagline' => 'Forum software — needs MongoDB'],
        'nextcloud' => ['title' => 'Nextcloud', 'tagline' => 'Private file sync and share'],
        'joomla' => ['title' => 'Joomla', 'tagline' => 'Flexible content management system'],
        'moodle' => ['title' => 'Moodle', 'tagline' => 'Online courses and learning'],
        'mautic' => ['title' => 'Mautic', 'tagline' => 'Marketing automation and campaigns'],
        'craftcms' => ['title' => 'Craft CMS', 'tagline' => 'Content management for developers'],
        'akaunting' => ['title' => 'Akaunting', 'tagline' => 'Accounting and invoicing'],
        'statamic' => ['title' => 'Statamic', 'tagline' => 'Flat-file CMS — no database needed'],
        'prestashop' => ['title' => 'PrestaShop', 'tagline' => 'Online store and e-commerce'],
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
        'database' => 'This application needs :engines, which this server does not have.',
        'php' => 'This server does not have PHP installed.',
        'node' => 'This server does not have Node.js installed.',
        'web_server' => 'This application is not available on :web_server servers yet.',
    ],

    'git_source' => [
        'account' => 'From a connected account',
        'public_url' => 'Paste a public repository URL',
    ],

    'fields' => [
        'company_name' => 'Company name',
        'company_email' => 'Company email',
        'locale' => 'Locale',
        'site_name' => 'Site name',
        'language' => 'Language',
        'admin_name' => 'Administrator name',
        'admin_first_name' => 'Administrator first name',
        'admin_last_name' => 'Administrator last name',
        'short_name' => 'Short name',
        'shop_name' => 'Shop name',
        'country' => 'Country',
        'timezone' => 'Time zone',
        'rendering_type' => 'Rendering type',
        'name' => 'Name',
        'domain' => 'Domain',
        'system_user_id' => 'System user',
        'php_version' => 'PHP version',
        'node_version' => 'Node.js version',
        'app_port' => 'App port',
        'web_root' => 'Web root',
        'build_command' => 'Build command',
        'start_command' => 'Start command',
        'package_manager' => 'Package manager',
        'git_source' => 'Source',
        'git_account_id' => 'Git account',
        'repository' => 'Repository',
        'repository_url' => 'Repository URL',
        'branch' => 'Branch',
        'site_title' => 'Site title',
        'admin_user' => 'Admin username',
        'admin_username' => 'Admin username',
        'admin_email' => 'Admin email',
        'admin_password' => 'Admin password',
        'site_language' => 'Site language',
        'table_prefix' => 'Table prefix',
    ],

    'help' => [
        'start_command' => 'The entry file, for example \"node server.js\". Not \"npm start\" — a package manager forks the real process, so shutdown signals never reach it.',
        'app_port' => 'Left empty, the panel picks a free one.',
        'rendering_type' => 'Server-side rendering runs your app and proxies to it. The other two build to files the web server hands out directly — faster, and nothing to keep running.',
        'repository_url' => 'A public repository — no account needed. Must be an https:// address.',
        'build_command' => 'Run after the code is fetched, e.g. composer install --no-dev',
        'package_manager' => 'What installs and builds your dependencies. Fills in the build command below — edit it freely afterward.',
    ],

    'steps' => [
        'create_database' => 'Creating the database',
        'download' => 'Downloading the application',
        'extract' => 'Unpacking the files',
        'configure' => 'Writing the configuration',
        'install_cli' => 'Installing the setup tool',
        'install_app' => 'Running the installer',
        'clone' => 'Cloning the repository',
        'fetch' => 'Fetching the latest code',
        'checkout' => 'Checking out the branch',
        'build' => 'Running the build command',
        'write_credential' => 'Preparing git access',
        'create_directory' => 'Creating the directory',
        'set_ownership' => 'Setting ownership',
        'placeholder' => 'Adding a placeholder page',
        'write_config' => 'Writing the site config',
        'test_config' => 'Testing the config',
        'reload' => 'Reloading the web server',
        'start_app' => 'Starting the application',
        'write_unit' => 'Preparing the service',
        'restart_app' => 'Restarting the application',
        'harden' => 'Applying security settings',
        'trust_domain' => 'Trusting the domain',
        'set_password' => 'Setting the admin password',
        'install_cache' => 'Installing the cache plugin',
        'worker' => 'The background worker stopped',
    ],
    'port_free' => 'Port :port is free.',

    'rendering' => [
        'php' => 'PHP application (Laravel, Symfony, plain PHP)',
        'ssr' => 'Server-side rendering (runs a process)',
        'csr' => 'Client-side rendering (built to files)',
        'static' => 'Static site (built to files)',
    ],

    'package_manager' => [
        'npm' => 'npm',
        'yarn' => 'Yarn',
        'pnpm' => 'pnpm',
        'bun' => 'Bun',
    ],

];
