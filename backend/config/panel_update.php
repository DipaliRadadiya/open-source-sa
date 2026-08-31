<?php

return [
    /*
     * Where the panel looks for newer releases. The default is the public
     * GitHub Releases API for the OSS repository — no token, no auth, so a
     * self-hosted panel can check for updates out of the box.
     */
    'releases_url' => env(
        'PANEL_RELEASES_URL',
        'https://api.github.com/repos/DipaliRadadiya/open-source-sa/releases/latest',
    ),

    /*
     * How long a successful availability check is cached. GitHub's unauthenticated
     * rate limit is per-IP and shared with everything else on the box, so the
     * dashboard must not hit the API on every page load.
     */
    'cache_ttl_minutes' => (int) env('PANEL_UPDATE_CACHE_TTL', 60),

    /*
     * Outbound call budget. A slow or hanging release host must never hold a
     * php-fpm worker open — the check is a nicety, the panel works without it.
     */
    'timeout_seconds' => (int) env('PANEL_UPDATE_TIMEOUT', 8),

    /*
     * Preflight thresholds for an in-place update. The frontend build (npm ci
     * + next build) is by far the heaviest step: install.sh calls it "the slow
     * part" and it is what fails first on a small VPS.
     *
     * The memory floor is measured, not chosen. Building this frontend (725
     * files, 58 routes) inside a memory cgroup on 2 vCPUs: 2000 MB is killed
     * during compilation, 2500 MB compiles and is then killed during static
     * generation, 3000 MB succeeds in 43s. 2560 MB is the lowest figure that
     * passes with the build-worker cap now set in next.config.mjs, and it is
     * checked against available memory *plus free swap* — see
     * UpdatePreflight::freeMemory(), and install.sh's configure_swap(), which
     * sizes the swapfile to clear this same number.
     *
     * The old default of 768 MB was not a floor at all: it passed on boxes
     * where the build was certain to be OOM-killed minutes later, which is the
     * worst thing a preflight check can do. PANEL_UPDATE_MIN_MEMORY_MB is the
     * escape hatch if this proves too strict on a real server.
     */
    /*
     * Where the runner's script and state file live. Outside the repository
     * on purpose: both would be destroyed by the checkout they are recording.
     */
    'state_dir' => env('PANEL_UPDATE_STATE_DIR', '/var/lib/panel-update'),

    /*
     * PHP the update shells out to. The running process cannot be asked — it
     * is php-fpm, and the script must survive that being reloaded.
     */
    'php_version' => env('PANEL_PHP_VERSION', '8.4'),

    /*
     * Directory holding the node binary install.sh placed on the box. Pinned
     * into PATH for the frontend build: npm's shebang is `env node`, so an
     * unpinned PATH silently builds with whatever node is first.
     */
    'node_bin_dir' => env('PANEL_NODE_BIN_DIR', '/opt/fnm/aliases/default/bin'),

    /*
     * Units the update reloads/restarts. install.sh names them from the panel
     * slug, so an operator who changed the slug must set these too.
     */
    'services' => [
        'php_fpm' => env('PANEL_PHP_FPM_SERVICE', 'panel-fpm.service'),
        'frontend' => env('PANEL_FRONTEND_SERVICE', 'panel-frontend.service'),
        'queue' => env('PANEL_QUEUE_SERVICE', 'panel-queue.service'),
    ],

    /*
     * The directory holding `shared/`, `releases/` and `current` — or, on a
     * legacy install, the checkout itself.
     *
     * Recorded rather than inferred because a migrated panel cannot work it
     * out. Services are started through `current/backend`, but PHP resolves
     * symlinks in `__DIR__`, so `base_path()` reports the release directory
     * and the panel has no way to know which of its ancestors is the root.
     * PanelLayout falls back to inference when this is unset, which is every
     * install made before the migration existed.
     */
    'root' => env('PANEL_ROOT'),

    /*
     * The account the panel's services run as. Composer and npm run as this
     * user, not as root: install.sh builds the frontend that way, and a build
     * run as root leaves node_modules and .next owned by root under a service
     * that is not — the two disagreeing is a whole class of post-update
     * breakage that only shows up at runtime.
     */
    'app_user' => env('PANEL_APP_USER', 'panel'),

    'preflight' => [
        'min_free_disk_mb' => (int) env('PANEL_UPDATE_MIN_DISK_MB', 2048),
        'min_free_memory_mb' => (int) env('PANEL_UPDATE_MIN_MEMORY_MB', 2560),
    ],
];
