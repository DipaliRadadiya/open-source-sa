// Gated purely on role === "admin", so a fixed list. `key` → `admin.nav.<key>`; `icon`
// is a kebab-case Lucide name.
export const ADMIN_NAV = [
  { key: "dashboard", url: "/admin", icon: "layout-dashboard" },
  { key: "users", url: "/admin/users", icon: "users" },
  { key: "roles", url: "/admin/roles", icon: "shield-check" },
  { key: "activityLog", url: "/admin/activity-log", icon: "scroll-text" },
  { key: "central", url: "/admin/central", icon: "plug-zap" },
  { key: "panelUpdate", url: "/admin/panel-update", icon: "arrow-up-circle" },
  { key: "doctor", url: "/admin/doctor", icon: "stethoscope" },
  { key: "errorLogs", url: "/admin/error-logs", icon: "bug" },
];

// Dashboard is the index, so it matches only exactly; the rest match by prefix
// so nested detail pages keep their parent nav item highlighted.
export function isAdminNavActive(pathname, url) {
  return url === "/admin" ? pathname === "/admin" : pathname.startsWith(url);
}
