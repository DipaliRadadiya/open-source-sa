// Shared sidebar nav-item styling, used by both the server and admin panel
// sidebars so they cannot drift.
export const NAV_ITEM_CLASS =
  "relative h-9 pl-3 transition-colors data-[active=true]:bg-primary/10 data-[active=true]:font-medium data-[active=true]:text-[color-mix(in_oklch,var(--primary)_80%,var(--foreground))] data-[active=true]:hover:bg-primary/15 data-[active=true]:hover:text-[color-mix(in_oklch,var(--primary)_80%,var(--foreground))] data-[active=true]:before:absolute data-[active=true]:before:inset-y-1 data-[active=true]:before:left-0 data-[active=true]:before:w-1 data-[active=true]:before:rounded-r-full data-[active=true]:before:bg-primary group-data-[collapsible=icon]:before:hidden";

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

// Returns an array to keep catalog order; raw `sub_level` is the fallback title
// for older catalogs.
export function groupBySubLevel(items) {
  const groups = [];
  const byKey = new Map();

  for (const item of items || []) {
    const key = item.sub_level || "";
    let group = byKey.get(key);
    if (!group) {
      group = { key, title: item.sub_level_title || key, items: [] };
      byKey.set(key, group);
      groups.push(group);
    }
    group.items.push(item);
  }

  return groups;
}
