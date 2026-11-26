export const ADMIN_NAV = [
  { key: "dashboard", url: "/admin", icon: "layout-dashboard" },
  { key: "users", group: "access", url: "/admin/users", icon: "users" },
  { key: "roles", group: "access", url: "/admin/roles", icon: "shield-check" },
  { key: "activityLog", group: "access", url: "/admin/activity-log", icon: "scroll-text" },
  { key: "central", group: "system", url: "/admin/central", icon: "plug-zap" },
  { key: "panelUpdate", group: "system", url: "/admin/panel-update", icon: "arrow-up-circle" },
  { key: "doctor", group: "system", url: "/admin/doctor", icon: "stethoscope" },
  { key: "errorLogs", group: "system", url: "/admin/error-logs", icon: "bug" },
];

export function isAdminNavActive(pathname, url) {
  return url === "/admin" ? pathname === "/admin" : pathname.startsWith(url);
}
