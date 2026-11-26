export const NAV_ITEM_CLASS =
  "relative h-9 gap-3 rounded-[2px] px-2.5 font-medium text-sidebar-foreground/80 transition-colors hover:bg-sidebar-accent/60 hover:text-sidebar-foreground [&_svg]:size-[18px] [&_svg]:text-muted-foreground hover:[&_svg]:text-foreground " +
  "data-[active=true]:bg-sidebar-accent data-[active=true]:text-sidebar-accent-foreground data-[active=true]:hover:bg-sidebar-accent data-[active=true]:hover:text-sidebar-accent-foreground data-[active=true]:[&_svg]:text-[var(--primary-700,var(--primary))] dark:data-[active=true]:[&_svg]:text-primary data-[active=true]:before:absolute data-[active=true]:before:inset-y-0 data-[active=true]:before:left-0 data-[active=true]:before:w-[3px] data-[active=true]:before:bg-primary";

export function isNavActive(pathname, url) {
  if (!pathname || !url) return false;
  return pathname === url || pathname.startsWith(`${url}/`);
}

export function findActiveNavItem(items, pathname) {
  return (items || [])
    .filter((item) => isNavActive(pathname, item.href ?? item.url))
    .sort((a, b) => (b.href ?? b.url).length - (a.href ?? a.url).length)[0];
}

export function applicationNavHref(applicationId, url) {
  return `/applications/${applicationId}${url ?? ""}`;
}

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

export function resolveNavItems(items, applicationId) {
  return (items || [])
    .filter((item) => item.url !== null && item.url !== undefined)
    .map((item) =>
      item.level === "application" && applicationId
        ? { ...item, href: applicationNavHref(applicationId, item.url) }
        : { ...item, href: item.url },
    );
}

export function navTitle(item, t) {
  if (item.name === "app_firewall") return t("navTitles.app_firewall");
  return item.title;
}

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

export const COLLAPSED_GROUPS = new Set(["software"]);

export const SIDEBAR_GROUPS_COOKIE = "sidebar_groups";

export function parseSidebarGroups(raw) {
  if (!raw) return {};
  try {
    const value = JSON.parse(raw);
    if (!value || typeof value !== "object" || Array.isArray(value)) return {};
    return Object.fromEntries(Object.entries(value).filter(([key, open]) => typeof open === "boolean" && key.length <= 64));
  } catch {
    return {};
  }
}

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
  for (const group of groups) {
    if (group.key === "catalog:server" || group.key === "catalog:application") {
      group.title = null;
    }
  }
  return groups.sort((a, b) => rank(a) - rank(b));
}
