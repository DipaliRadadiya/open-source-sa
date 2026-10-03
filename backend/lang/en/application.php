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
        'docker' => ['title' => 'Docker container', 'tagline' => 'Any image, from any registry, proxied by nginx.'],
        'ghost' => ['title' => 'Ghost', 'tagline' => 'Publishing and newsletters — runs with its own MySQL'],
        'matomo' => ['title' => 'Matomo', 'tagline' => 'Web analytics on your own server — a Google Analytics replacement'],
        'mattermost' => ['title' => 'Mattermost', 'tagline' => 'Team chat — runs with its own PostgreSQL'],
        'chatwoot' => ['title' => 'Chatwoot', 'tagline' => 'Customer support inbox — runs with its own PostgreSQL and Redis'],
        'excalidraw' => ['title' => 'Excalidraw', 'tagline' => 'Whiteboard — drawings stay in your browser; sharing and live collaboration go through excalidraw.com'],
        'forgejo' => ['title' => 'Forgejo', 'tagline' => 'Git hosting — community-governed fork of Gitea'],
        'freshrss' => ['title' => 'FreshRSS', 'tagline' => 'Self-hosted feed reader'],
        'gitea' => ['title' => 'Gitea', 'tagline' => 'Git hosting with issues and pull requests'],
        'glance' => ['title' => 'Glance', 'tagline' => 'Dashboard for feeds, monitors and bookmarks'],
        'homepage' => ['title' => 'Homepage', 'tagline' => 'Start page for the services on your server'],
        'ittools' => ['title' => 'IT-Tools', 'tagline' => 'Developer utilities — nothing is stored'],
        'stirlingpdf' => ['title' => 'Stirling PDF', 'tagline' => 'Split, merge, sign and convert PDFs in the browser'],
        'vaultwarden' => ['title' => 'Vaultwarden', 'tagline' => 'Password manager, compatible with Bitwarden apps'],
        'nocodb' => ['title' => 'NocoDB', 'tagline' => 'Spreadsheet interface for a database — runs with its own PostgreSQL'],
        'metabase' => ['title' => 'Metabase', 'tagline' => 'Dashboards and questions over your data — runs with its own PostgreSQL'],
        'wikijs' => ['title' => 'Wiki.js', 'tagline' => 'Documentation wiki — runs with its own PostgreSQL'],
        'grafana' => ['title' => 'Grafana', 'tagline' => 'Dashboards and alerts over your metrics — the admin password is generated per site'],
        'bookstack' => ['title' => 'BookStack', 'tagline' => 'Documentation in shelves, books and pages — runs with its own MariaDB'],
        'wordpress_container' => ['title' => 'WordPress', 'tagline' => 'Blog and website builder — runs as a container with its own MariaDB'],
        'wordpress' => ['title' => 'WordPress', 'tagline' => 'Blog and website builder'],
        'phpmyadmin' => ['title' => 'phpMyAdmin', 'tagline' => 'Manage your databases in the browser'],
        'uptimekuma' => ['title' => 'Uptime Kuma', 'tagline' => 'Uptime monitoring and status pages'],
        'n8n' => ['title' => 'n8n', 'tagline' => 'Workflow automation (fair-code licence)'],
        'nodered' => ['title' => 'Node-RED', 'tagline' => 'Wire up devices, APIs and services'],
        'nodebb' => ['title' => 'NodeBB', 'tagline' => 'Forum software — needs MongoDB or PostgreSQL'],
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
        'stack' => 'This server runs containers only, so it does not host this kind of application.',
        'database' => 'This application needs :engines, which this server does not have.',
        'php' => 'This server does not have PHP installed.',
        'php_version_install' => 'This server has no PHP version :type runs on (:range). Install PHP :version from the PHP screen first.',
        'php_version_none' => 'This server has no PHP version :type runs on (:range), and none of those versions can be installed from this server\'s package repository.',
        'node' => 'This server does not have Node.js installed.',
        'web_server' => 'This application is not available on :web_server servers yet.',
    ],

    'git_source' => [
        'account' => 'From a connected account',
        'public_url' => 'Paste a public repository URL',
    ],

    'fields' => [
        'memory_limit' => 'Memory limit',
        'cpu_limit' => 'CPU limit',
        'compose' => 'Compose file',
        'image' => 'Image',
        'registry_id' => 'Registry',
        'container_port' => 'Container port',
        'docker_network' => 'Network',
        'docker_mode' => 'How to run it',
        'docker_network_new' => 'New network',
        'volume_new' => 'New volume',
        'volume_path' => 'Volume path',
        'database_engine' => 'Database engine',
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
        'deploy_script' => 'Deploy script',
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
        'mailer_name' => 'Mail \'from\' name',
        'mailer_email' => 'Mail \'from\' address',
        'mailer_host' => 'SMTP host',
        'mailer_port' => 'SMTP port',
        'mailer_username' => 'SMTP username',
        'mailer_password' => 'SMTP password',
    ],

    /*
    | Example values, shown as ghost text in an empty field.
    |
    | A placeholder is NOT a default: it is never submitted. Anything with a
    | correct value the panel can pick lives in the field's `default` instead,
    | which the form pre-fills and the request carries — a table prefix is a
    | default, an email address is a placeholder. Getting that backwards ships
    | a form that looks filled in and posts null.
    |
    | Keyed by field name, not by site type, so one entry serves every type
    | declaring that field — the same arrangement as `fields` and `help`.
    | Localized because these are read by a person: an example is only an
    | example if it is in a language they read.
    */
    'placeholders' => [
        'cpu_limit' => 'No limit',
        'mailer_host' => 'smtp.example.com',
        'mailer_port' => '587',
        'site_title' => 'My Site',
        'site_name' => 'My Site',
        'shop_name' => 'My Shop',
        'company_name' => 'My Company',
        'short_name' => 'mysite',
        'mailer_name' => 'My Site',
        'admin_email' => 'you@example.com',
        'company_email' => 'you@example.com',
        'mailer_email' => 'no-reply@example.com',
        'mailer_username' => 'no-reply@example.com',
        'timezone' => 'Europe/Berlin',
        'repository_url' => 'https://github.com/you/repo.git',
        'build_command' => 'npm ci && npm run build',
        'start_command' => 'node server.js',
    ],

    'options' => [
        'docker_mode' => [
            'simple' => 'Simple — an image and a port',
            'compose' => 'Compose file — write it yourself',
        ],
    ],

    'help' => [
        'memory_limit_app' => 'Optional. The most memory this app\'s own container may use, for example 512m or 2g. A bare number means bytes to Docker, not megabytes. Left empty it gets :default. Any database this app brings has its own separate ceiling.',
        'memory_limit_app_floor' => 'Optional. The most memory this app\'s own container may use. Left empty it gets :default, which is what this app has been measured to need — set it lower and the app may not start, and it will fail as a 502 with nothing about memory to explain it. Any database this app brings has its own separate ceiling.',
        'cpu_limit_app' => 'Optional. How many CPU cores this app\'s own container may use — 1 for one full core, 0.5 for half. This server has :cores, and Docker will not start a container asking for more. Left empty there is no CPU limit at all. Going past this does not kill anything; the container waits, so the symptom is slowness rather than an error. Any database this app brings is not limited by it.',
        'memory_limit' => 'Optional. The most memory this container may use, for example 512m or 2g. A bare number means bytes to Docker, not megabytes. Left empty it gets the server default of :default. This is a ceiling, not a reservation — nothing is set aside — and going past it gets the container killed and restarted.',
        'cpu_limit' => 'Optional. How many CPU cores this container may use — 1 for one full core, 0.5 for half. This server has :cores, and Docker will not start a container asking for more. Left empty there is no CPU limit at all. Going past this one does not kill anything; the container waits, so the symptom is slowness rather than an error.',
        'compose' => 'Optional. Paste your own compose file and everything Compose supports is supported — multiple services, named volumes, healthchecks. Leave it empty and the panel writes one from the fields above. Ports must publish to 127.0.0.1 and bind mounts must stay inside this application\'s directory; anything else is refused with the reason.',
        'image' => 'The image to run, with an explicit tag — `nginx:1.27-alpine`. A bare name pulls `latest`, which makes a deploy unreproducible and a rollback meaningless.',
        'registry_id' => 'Leave this empty for a public image — that is the normal case. To run a private one, add a credential on the Docker page under Registry credentials, then choose it here. It applies to a pasted compose file too, since any image it names can be private.',
        'container_port' => 'The port your application listens on inside the container. The panel allocates the port on the server itself and points nginx at it.',
        'docker_network' => 'Join a Docker network so this container and others on it can reach each other by name. Leave empty for Docker\'s default bridge, where they cannot. Create networks on the Docker page.',
        'docker_mode' => 'Simple gives you an image and a port and the panel writes the compose file. Compose file is for anything else — you write it, and the panel still enforces loopback publishing, a memory ceiling and bounded logs.',
        'docker_network_new' => 'Leave the picker above empty and type a name here to create a new network and join this site to it. Refused if a network with that name already exists — pick it above instead.',
        'volume_new' => 'Create a volume with this name and mount it into the site, so its data survives the container being rebuilt. Refused if a volume with that name already exists.',
        'volume_path' => 'Where the volume appears inside the container, for example /var/lib/ghost/content. Not the site\'s own directory — a volume there would hide the site\'s files.',
        'table_prefix_random' => 'Leave empty and a random prefix is generated, keeping the tables apart if the database is ever shared.',
        'timezone' => 'Timezone for the site, e.g. America/New_York or Europe/Berlin. See Settings → General → Timezone.',
        'table_prefix_optional' => 'Optional. Clear it and the tables are created with no prefix at all.',
        'start_command' => 'The entry file, for example "node server.js". Not "npm start" — a package manager forks the real process, so shutdown signals never reach it.',
        'app_port' => 'Left empty, the panel picks a free one.',
        'rendering_type' => 'Server-side rendering runs your app and proxies to it. The other two build to files the web server hands out directly — faster, and nothing to keep running.',
        'repository_url' => 'A public repository — no account needed. Must be an https:// address.',
        'build_command' => 'Run after the code is fetched, e.g. composer install --no-dev',
        'deploy_script' => 'Runs after the code is fetched, as your site user and on this site\'s own PHP version. Leave empty to use the build command.',
        'package_manager' => 'What installs and builds your dependencies. Fills in the build command below — edit it freely afterward.',
    ],

    'steps' => [
        'create_database' => 'Creating the database',
        'download' => 'Downloading the application',
        'extract' => 'Unpacking the files',
        'configure' => 'Writing the configuration',
        'install_cli' => 'Installing the setup tool',
        'install_app' => 'Running the installer',
        'init' => 'Setting up the repository',
        'fetch' => 'Fetching the latest code',
        'checkout' => 'Checking out the branch',
        'seed_env' => 'Preparing the environment file',
        'build' => 'Running the build command',
        'write_credential' => 'Preparing git access',
        'ensure_account' => 'Creating the system account',
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
        'script' => 'Running the deploy script',
        'dependencies' => 'Checking the dependencies',
        'verify' => 'Checking the site answers',
        'verify_serving' => 'Checking the site answers',
        'create_admin' => 'Creating the admin account',
        'schedule_cron' => 'Scheduling background jobs',
        'worker' => 'The background worker stopped',
    ],
    /*
    | Refusing to relabel a site, keyed by reason. Each says what is in the
    | way and what to do instead — "invalid selection" would be true of all
    | five and useful for none.
    */
    'site_type_change' => [
        'git_cannot_change' => 'This site is deployed from a git repository, so its type cannot be changed. Its Deployments, Workers and environment file screens exist because of that type, and removing them would not stop the background workers running or the deploy webhook accepting pushes — it would only take away the screens that manage them.',
        'git_not_a_target' => 'A site cannot be changed into a git deployment. That requires a repository, a branch and a deploy script for the panel to own, which cannot be created from the files already on the server. Create a git application instead.',
        'unchanged' => 'This site is already set to that type.',
        'not_suggestable' => 'This site cannot be changed to that type. Only applications the panel can recognise on disk can be relabelled — everything else would claim features the site has no way to use.',
        'only_from_generic' => 'Only a Custom PHP or Static site can be relabelled to another application type. This site is already set to a specific application, and changing one application into another is not something a label can do.',
        'no_evidence' => 'Nothing on this site looks like :type. Upload the application first, then run Detect again — the panel only changes a site\'s type when it can see the application in the site\'s own directory.',
    ],

    /*
    | Why provisioning failed, keyed by the `failed_reason` code on the
    | application. Only set where the exit status genuinely identifies
    | the cause; most failures carry the step and reference instead.
    */
    'failure_reason' => [
        'attached_database_engine_mismatch' => 'This application already has a database attached, but it runs on an engine this application cannot use. Detach it, or attach one on a supported engine, and try again.',
        'serving_error' => 'The application started but answers every request with an error. Its assets were most likely not built completely — check the application log for details.',
        'not_answering' => 'The application started but never answered a request. Check the application log for why it is not listening.',
        'owner_not_created' => 'The application started but its administrator could not be confirmed. The site was not handed over, because until an administrator exists anyone who opens it can create one. Try again; if it keeps failing, check the application log.',
        'claim_refused' => 'The application started, but it refused the administrator the panel tried to create, so the site has no owner. The usual cause is the e-mail address: Chatwoot rejects disposable and placeholder domains, and test.com is one of them — use a real address. The application does not log why, so there is nothing to find in its log. Delete the site and create it again with a different address.',
        'app_not_ready' => 'The application started but did not finish starting up within 2 minutes, so its administrator could not be created. Check the application log, then try again.',
        'out_of_memory' => 'The server ran out of memory during this step and it was stopped by the system. Free some memory, or add swap, and try again.',
        'no_build_tools' => 'This step needed to compile a native module, and this server has no compiler installed. Install the build tools from the setup screen, then try again. Choosing a different Node version may also help, since some versions ship ready-built binaries — but which ones do is up to each package, so it is not a reliable fix on its own.',
        'composer_platform' => 'Composer could not install this application\'s dependencies under the PHP version this site is set to. The site\'s PHP version, or one of the extensions it needs, does not meet what the project requires. Change the site\'s PHP version to one the project supports, or install the missing extension, and deploy again.',
        'registry_auth' => 'Docker could not pull this image because the registry refused it. Either the image name or tag is wrong, or the image is private — Docker reports both the same way, so check the reference first. If the image is private, note that the panel cannot sign in to a registry yet, so it can only run images that are publicly available.',
        'registry_credentials_rejected' => 'The registry refused the credential this site pulls with. The token has most likely expired or been revoked — rotate it on the Docker page and deploy again. The image reference itself is fine; the registry answered, it just would not accept this username and token.',
        'container_restarting' => 'The container starts and then stops again, over and over, so the site cannot serve. Its own log is the place to look — usually a command or entrypoint that exits immediately, a missing environment variable, or a configuration file the image could not read.',
        'script_git_auth' => 'Your deploy script runs a git command (usually git pull) that needs to log in to the repository, and the script has no login, so it fails on a private repository. You do not need it: the panel already downloads the latest code with the connected account before your script runs. Remove that line from the deploy script and deploy again.',
        'script_php_missing' => 'Your deploy script uses a {PHPxx} variable for a PHP version that is not installed on this server. Install that version on the PHP screen, or use {php} for the site\'s own version, then deploy again.',
        'composer_dependencies_missing' => 'This project requires Composer dependencies and none were installed, so the application has no vendor/autoload.php and every request to it will fail. Add a build step that runs composer install to the deployment script, then deploy again.',
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

    'supervisor_installing' => 'Installing supervisor, which workers run under. This takes a moment — create the worker again once it finishes.',

    'placeholder_page' => [
        'lede' => 'This site is ready and serving. Replace this page with your own — until you do, every visitor sees it.',
        'php_running' => 'PHP is running on this site',
        'step_files_title' => 'Upload your files',
        'step_files_body' => 'Use the panel\'s File Manager, or connect over SFTP with this site\'s system user.',
        'step_deploy_title' => 'Or deploy from git',
        'step_deploy_body' => 'Point the site at a repository and the panel will pull and build it on every push.',
        'foot' => 'Placeholder page created by the control panel.',
    ],

    'supervisor_mode' => [
        'systemd' => 'systemd unit',
        'pm2' => 'PM2 (adopted)',
    ],

    'disabled_page' => [
        'title' => 'Site unavailable',
        'heading' => 'This site is temporarily unavailable',
        'lede' => 'It has been taken offline by its owner. Please try again later.',
        'foot' => 'Served by the control panel.',
    ],

    // A deploy that failed after its checkout left the new code live.
    // See Application::codeOnDisk().
    'code_on_disk' => [
        'incomplete' => 'The last deploy failed after the new code was put in place, so the site is running commit :commit, which is not fully deployed. Fix the problem and deploy again.',
    ],

    // A delivery for a site whose deploy-on-push is switched off. See
    // ApplicationWebhookController::receive().
    'webhook_delivery' => [
        'disabled' => 'Deploy on push is turned off for this site in the panel, so nothing was deployed. Turn it on again in the panel, or delete this webhook.',
    ],

    // Why deploy-on-push still needs the webhook added by hand. See
    // WebhookRegistrar.
    'webhook_registration' => [
        'no_account' => 'This site deploys from a public URL, not a connected Git account, so the panel cannot add the webhook for you. Add it in your repository settings with the URL and secret below.',
        'signing_token' => 'GitLab creates signing tokens itself, so the panel cannot add this webhook for you. Add it in the repository\'s Webhooks settings with the URL below and your signing token.',
        'not_public' => 'The panel\'s address is not reachable from the internet, so GitHub, GitLab or Bitbucket could not deliver to it. Give the panel a public address, or add the webhook by hand once it has one.',
        'provider_refused' => 'The Git provider did not let the panel add the webhook. The connected token probably lacks permission to manage webhooks on this repository. Add it by hand with the URL and secret below, or reconnect the account with that permission.',
        'removal_refused' => 'Deploy on push is off, but the Git provider did not let the panel remove the webhook it added. The connected token probably lacks permission to delete webhooks. Pushes will still be sent and refused until you delete the webhook in the repository\'s settings.',
    ],
];
