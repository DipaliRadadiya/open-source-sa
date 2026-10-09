// Shared sidebar nav-item styling, used by both the server and admin panel
// sidebars so they cannot drift.
export const NAV_ITEM_CLASS =
  "relative h-9 gap-3 rounded-lg px-2.5 font-medium text-sidebar-foreground/80 transition-colors hover:bg-sidebar-accent/60 hover:text-sidebar-foreground [&_svg]:size-[18px] [&_svg]:text-muted-foreground hover:[&_svg]:text-foreground data-[active=true]:bg-sidebar-accent data-[active=true]:text-sidebar-accent-foreground data-[active=true]:hover:bg-sidebar-accent data-[active=true]:hover:text-sidebar-accent-foreground data-[active=true]:[&_svg]:text-primary";

// A nav item stays active on its sub-pages (e.g. `/settings/server`). Longest
// match wins when two items share a prefix.
export function isNavActive(pathname, url) {
  if (!pathname || !url) return false;
  return pathname === url || pathname.startsWith(`${url}/`);
}

export function findActiveNavItem(items, pathname) {
  return (items || [])
    .filter((item) => isNavActive(pathname, item.href ?? item.url))
    .sort((a, b) => (b.href ?? b.url).length - (a.href ?? a.url).length)[0];
}

// Catalog `url`s are relative (`""` is the dashboard); unprefixed they 404 or
// land on the server-wide screen.
export function applicationNavHref(applicationId, url) {
  return `/applications/${applicationId}${url ?? ""}`;
}

// The catalog advertises every application screen the backend supports; only
// built routes are linked, the rest are held back until their route lands.
const BUILT_APPLICATION_URLS = new Set([
  "",
  "/domains",
  "/deployment",
  "/environment",
  "/container",
  "/compose",
  "/logs",
  "/workers",
  "/files",
  "/security",
  "/bot-blocker",
  "/firewall",
  "/backups",
  "/clone",
  "/php",
  "/fail2ban",
  "/staging",
]);

export function isApplicationNavBuilt(url) {
  return BUILT_APPLICATION_URLS.has(url ?? "");
}

// The same contract for the server panel: unbuilt screens show as "SOON"
// instead of linking to a 404.
const BUILT_SERVER_URLS = new Set([
  "/dashboard",
  "/applications",
  "/databases",
  "/system-users",
  "/firewall",
  "/cron-jobs",
  "/fail2ban",
  "/logs",
  "/services",
  "/php",
  "/node",
  "/docker",
  "/settings",
  "/disk-cleaner",
  "/backups",
  "/activity-log",
  "/integrations/git",
  "/integrations/storage",
  "/integrations/registries",
  "/sync",
]);

export function isServerNavBuilt(url) {
  return BUILT_SERVER_URLS.has(url ?? "");
}

export function isNavBuilt(panel, url) {
  return panel === "application"
    ? isApplicationNavBuilt(url)
    : isServerNavBuilt(url);
}

// Resolves a catalog item for the panel it belongs to: application items get
// the `/applications/{id}` prefix, server items are already absolute.
export function resolveNavItems(items, applicationId) {
  return (items || [])
    // A null `url` is not a screen (e.g. Magic Login). `""` is the Dashboard, so
    // check for null, not falsiness.
    .filter((item) => item.url !== null && item.url !== undefined)
    .map((item) =>
      item.level === "application" && applicationId
        ? { ...item, href: applicationNavHref(applicationId, item.url) }
        : { ...item, href: item.url },
    );
}

// "8G" names the upstream ruleset, not the feature; only the label changes.
export function navTitle(item, t) {
  if (item.name === "app_firewall") return t("navTitles.app_firewall");
  return item.title;
}

// Groups named after what a person is trying to do, keyed on the catalog's
// stable `name`. The catalog only knows `server` and `integration`, which left
// eighteen items in one list. Anything not listed here keeps its catalog group
// (Integrations does), so a new backend screen still appears.
const GROUP_OF = {
  dashboard: "",
  application: "",
  database: "data",
  backup: "data",
  cronjob: "data",
  firewall: "security",
  fail2ban: "security",
  system_user: "security",
  php: "software",
  node: "software",
  docker: "software",
  service: "software",
  logs: "server",
  disk_cleaner: "server",
  sync: "server",
  activity_log: "server",
  setting: "server",
  app_dashboard: "",
  app_domain: "website",
  app_deployment: "website",
  app_container: "website",
  app_compose: "website",
  app_file: "website",
  app_log: "website",
  app_backup: "copies",
  app_staging: "copies",
  app_clone: "copies",
  app_security: "protection",
  app_firewall: "protection",
  app_bot_blocker: "protection",
  app_fail2ban: "protection",
  app_php: "configuration",
  app_environment: "configuration",
  app_worker: "configuration",
};

// Shut until needed: the screens a newcomer does not start with. Settings lives in
// "server" (not alone at the bottom, which read as an orphan), so that group stays open.
export const COLLAPSED_GROUPS = new Set(["software"]);

// Ordered by GROUP_ORDER, catalog order inside a group. Catalog groups keep
// their own translated title; the raw `sub_level` is the fallback for older
// catalogs. The caller names the rest from `common.navGroups`.
const GROUP_ORDER = ["", "data", "security", "software", "server", "website", "copies", "protection", "configuration"];

export function groupNavItems(items) {
  const groups = [];
  const byKey = new Map();

  for (const item of items || []) {
    const mapped = Object.hasOwn(GROUP_OF, item.name) ? GROUP_OF[item.name] : null;
    const key = mapped ?? `catalog:${item.sub_level || ""}`;
    let group = byKey.get(key);
    if (!group) {
      group = {
        key,
        // Catalog groups carry their own (translated) title; the top group has none.
        title: mapped === null ? item.sub_level_title || item.sub_level || null : null,
        named: mapped !== null && mapped !== "",
        items: [],
      };
      byKey.set(key, group);
      groups.push(group);
    }
    group.items.push(item);
  }

  const rank = (group) => {
    const index = GROUP_ORDER.indexOf(group.key);
    return index === -1 ? 500 : index;
  };
  // The catalog's own `server`/`application` group is the unnamed top group.
  for (const group of groups) {
    if (group.key === "catalog:server" || group.key === "catalog:application") {
      group.title = null;
    }
  }
  return groups.sort((a, b) => rank(a) - rank(b));
}
