/**
 * Fixture payloads for `/ui-preview`.
 *
 * Shaped by hand from the API resources they stand in for
 * (`ApplicationPhpSettingsResource`, `ApplicationFail2banController::show`) so
 * a screen can be looked at without a working backend — and, more usefully,
 * so states that are rare or destructive on a real server (over-committed
 * memory, an attack in progress, being banned from your own site) can be seen
 * on demand instead of waited for.
 *
 * Nothing here is used by the product. See the route's own comment.
 */
const MB = 1024 * 1024;

const phpSettings = {
  memory_limit: "256M",
  upload_max_filesize: "64M",
  post_max_size: "64M",
  max_execution_time: 120,
  max_input_time: 120,
  max_input_vars: 3000,
  session_gc_maxlifetime: 1440,
  pm_type: "ondemand",
  pm_max_children: 6,
  pm_max_requests: 500,
  open_basedir_enabled: true,
  disable_functions: "exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec",
  allow_url_fopen: true,
  php_timezone: "",
  auto_prepend_file: "",
  additional_directives: "",
};

const php = (overrides = {}, memory = {}) => ({
  application_id: 1,
  php_version: "8.3",
  available_versions: ["7.4", "8.0", "8.1", "8.2", "8.3", "8.4"],
  isolated: true,
  isolated_at: "08-08-2026 04:10:00",
  isolation_supported: true,
  runs_as: "companyblog",
  managed: true,
  settings: phpSettings,
  presets: [
    { key: "low", title: "Light", description: "A small site with occasional visitors.", pm_type: "ondemand", pm_max_children: 2 },
    { key: "balanced", title: "Balanced", description: "The usual choice.", pm_type: "ondemand", pm_max_children: 6 },
    { key: "high", title: "Busy", description: "A shop or a site with steady traffic.", pm_type: "dynamic", pm_max_children: 12 },
  ],
  memory: {
    total: 8192 * MB,
    committed: 3072 * MB,
    available: 5120 * MB,
    over_committed: false,
    sites: 5,
    this_site: 1536 * MB,
    ...memory,
  },
  suggested_disable_functions: "exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec",
  ...overrides,
});

export const PHP_STATES = {
  isolated: { label: "Isolated — normal", data: php() },
  shared: {
    label: "Shared PHP (limits locked)",
    data: php({ isolated: false, isolated_at: null, runs_as: "www-data" }),
  },
  overcommitted: {
    label: "Memory over-committed",
    data: php(
      { settings: { ...phpSettings, memory_limit: "1G", pm_max_children: 12 } },
      { total: 2048 * MB, committed: 1536 * MB, this_site: 512 * MB, sites: 4 },
    ),
  },
  unmanaged: { label: "Pool file edited by hand", data: php({ managed: false }) },
  unsupported: {
    label: "OpenLiteSpeed — no pools",
    data: php({ isolated: false, isolation_supported: false, runs_as: "www-data" }),
  },
};

const jail = (kind, over = {}) => ({
  jail: `app-1-${kind}`,
  enabled: true,
  banned: [],
  stats: { currently_failed: 0, total_failed: 0, currently_banned: 0, total_banned: 0 },
  ...over,
});

export const FAIL2BAN_STATES = {
  quiet: {
    label: "Watching — all quiet",
    data: { enabled: true, jails: [jail("generic"), jail("wplogin")], viewerIp: "198.51.100.7" },
  },
  off: {
    label: "Not watching",
    data: {
      enabled: false,
      jails: [jail("generic", { enabled: false, stats: null }), jail("wplogin", { enabled: false, stats: null })],
      viewerIp: "198.51.100.7",
    },
  },
  attack: {
    label: "Under attack",
    data: {
      enabled: true,
      viewerIp: "198.51.100.7",
      jails: [
        jail("generic", {
          banned: ["203.0.113.10", "203.0.113.44"],
          stats: { currently_failed: 6, total_failed: 412, currently_banned: 2, total_banned: 9 },
        }),
        jail("wplogin", {
          banned: ["203.0.113.10", "2001:db8::1f"],
          stats: { currently_failed: 3, total_failed: 128, currently_banned: 2, total_banned: 4 },
        }),
      ],
    },
  },
  lockedOut: {
    label: "You are banned",
    data: {
      enabled: true,
      viewerIp: "198.51.100.7",
      jails: [
        jail("generic", {
          banned: ["198.51.100.7", "203.0.113.10"],
          stats: { currently_failed: 1, total_failed: 57, currently_banned: 2, total_banned: 3 },
        }),
        jail("wplogin", { banned: [], stats: { currently_failed: 0, total_failed: 12, currently_banned: 0, total_banned: 1 } }),
      ],
    },
  },
};
