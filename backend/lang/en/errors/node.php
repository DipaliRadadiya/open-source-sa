<?php

/*
 * Node feature errors.
 */

return [
    'not_installed' => 'Node :version is not installed.',
    'version_in_use' => 'Node :version is used by :apps. Change those sites first.',
    'version_is_default' => 'This is the default version. Choose another default first.',
    'version_runs_panel' => 'The panel itself runs on Node :version. It cannot be removed.',
    'npm_target_unknown' => 'The npm release list could not be reached, so there is no way to tell which npm this Node version can run. Try again when the server has internet access, or run `php artisan runtimes:refresh-npm`.',
    'not_a_node_server' => 'This server hosts containers and runs no applications on the host itself, so there are no Node.js versions to manage. A container brings its own runtime.',
];
