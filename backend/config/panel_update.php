<?php

return [
    /*
     * Where the panel looks for newer releases. The default is the public GitHub Releases API
     * (no token needed), so a self-hosted panel can check for updates out of the box.
     */
    'releases_url' => env(
        'PANEL_RELEASES_URL',
        'https://api.github.com/repos/DipaliRadadiya/open-source-sa/releases/latest',
    ),

    /*
     * How long a successful availability check is cached. GitHub's unauthenticated rate limit is
     * per-IP, so the dashboard must not hit the API on every page load.
     */
    'cache_ttl_minutes' => (int) env('PANEL_UPDATE_CACHE_TTL', 60),

    /*
     * Outbound call budget. A hanging release host must never hold a php-fpm worker open.
     */
    'timeout_seconds' => (int) env('PANEL_UPDATE_TIMEOUT', 8),

    /*
     * Preflight thresholds for an in-place update. The frontend build (npm ci + next build) is
     * the heaviest step and fails first on a small VPS.
     *
     * The memory floor is measured (the build is OOM-killed at 2000–2500 MB) and is checked
     * against available memory *plus free swap* — see UpdatePreflight::freeMemory() and
     * install.sh's configure_swap(). PANEL_UPDATE_MIN_MEMORY_MB is the escape hatch.
     */
    /*
     * Where the runner's script and state file live. Outside the repository on purpose: the
     * checkout they are recording would destroy them.
     */
    'state_dir' => env('PANEL_UPDATE_STATE_DIR', '/var/lib/panel-update'),

    // Whether start() launches the script it wrote. Off only in the test suite (phpunit.xml):
    // launching is `sudo -n systemd-run`, which would run the generated script as root.
    'launch' => (bool) env('PANEL_UPDATE_LAUNCH', true),

    /*
     * PHP the update shells out to. The running process cannot be asked: it is php-fpm, which
     * the script must survive reloading.
     */
    'php_version' => env('PANEL_PHP_VERSION', '8.4'),

    /*
     * The binary itself, when the version is not enough to find it.
     *
     * Empty on nginx and Apache (`/usr/bin/php{version}`). install.sh writes it on OpenLiteSpeed,
     * where the panel runs on LSPHP under /usr/local/lsws. Resolution lives in PanelPhpBinary.
     */
    'php_binary' => env('PANEL_PHP_BIN', ''),

    /*
     * Directory holding the node binary install.sh placed. Pinned into PATH for the frontend
     * build: npm's shebang is `env node`, so an unpinned PATH builds with whatever node is first.
     */
    'node_bin_dir' => env('PANEL_NODE_BIN_DIR', '/opt/fnm/aliases/default/bin'),

    /*
     * Units the update reloads/restarts. install.sh names them from the panel slug; set these
     * too if the slug was changed.
     */
    'services' => [
        'php_fpm' => env('PANEL_PHP_FPM_SERVICE', 'panel-fpm.service'),
        'frontend' => env('PANEL_FRONTEND_SERVICE', 'panel-frontend.service'),
        'queue' => env('PANEL_QUEUE_SERVICE', 'panel-queue.service'),
    ],

    /*
     * The directory holding `shared/`, `releases/` and `current` — or, on a legacy install, the
     * checkout itself.
     *
     * Recorded, not inferred: services start through `current/backend` but PHP resolves symlinks
     * in `__DIR__`, so `base_path()` is the release directory. PanelLayout infers it when unset.
     */
    'root' => env('PANEL_ROOT'),

    /*
     * The account the panel's services run as. Composer and npm run as this user, not root: a
     * root build leaves node_modules and .next root-owned under a non-root service.
     */
    'app_user' => env('PANEL_APP_USER', 'panel'),

    'preflight' => [
        'min_free_disk_mb' => (int) env('PANEL_UPDATE_MIN_DISK_MB', 2048),
        'min_free_memory_mb' => (int) env('PANEL_UPDATE_MIN_MEMORY_MB', 3584),
    ],
];
