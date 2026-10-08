<?php

/*
 * Node feature errors.
 */

return [
    'not_installed' => 'Node :version is not installed.',
    'version_in_use' => 'Node :version is used by :apps. Switch those sites to another Node version first (each site\'s Node.js setting), or delete them.',
    'version_unknown' => 'Node.js :version does not exist. Choose a version from the list.',
    'version_is_default' => 'This is the default version. Choose another default first.',
    'version_runs_panel' => 'The panel itself runs on Node :version. It cannot be removed.',
    'npm_target_unknown' => 'The npm release list could not be reached, so there is no way to tell which npm this Node version can run. Try again when the server has internet access, or run `php artisan runtimes:refresh-npm`.',
    'not_a_node_server' => 'This server hosts containers and runs no applications on the host itself, so there are no Node.js versions to manage. A container brings its own runtime.',
    'change_not_node' => 'Only a site that runs on Node.js has a Node version to change.',
    'change_legacy_pm2' => 'This site still runs under PM2 from the previous panel. Switch it to the panel\'s process manager first, then change its Node version.',
    'change_in_progress' => 'This site is already being switched to Node :version. Wait for that to finish.',
    'change_use_endpoint' => 'The Node version is changed with its own action (PUT /applications/{application}/node-version), which restarts the site and checks it.',
    'change_failed' => [
        'did_not_start' => 'The site did not start on Node :target, so it was switched back to Node :current and is running as before. Check the site\'s log for the reason.',
        'rollback_failed' => 'The site did not start on Node :target, and switching it back to Node :current did not work either. Check the site\'s log and restart it.',
        'unit_write' => 'The site\'s service could not be updated for Node :target. Nothing changed; it is still on Node :current.',
        'install_pm2' => 'PM2 could not be installed into Node :target, which this site needs to run several processes. Nothing changed; it is still on Node :current.',
        'worker' => 'The switch to Node :target stopped before it finished. The site is recorded as Node :current; check that it is running, then try again.',
    ],
    'remove_failed' => 'Node :version could not be removed. Quote the reference below to support.',
    'remove_failed_said' => 'Node :version could not be removed. fnm said: “:output”',
    'install_in_progress' => 'Node :version is already being installed. Wait for that install to finish.',
];
