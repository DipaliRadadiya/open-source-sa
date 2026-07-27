// Static admin-panel navigation. Unlike the server panel (permission-driven),
// the admin panel is gated purely on role === "admin", so its nav is a fixed
// list. `key` maps to the `admin.nav.<key>` i18n message; `icon` is a
// kebab-case Lucide name (rendered via <NavIcon />).
export const ADMIN_NAV = [
  { key: "dashboard", url: "/admin", icon: "layout-dashboard" },
  { key: "users", url: "/admin/users", icon: "users" },
  { key: "roles", url: "/admin/roles", icon: "shield-check" },
  { key: "activityLog", url: "/admin/activity-log", icon: "scroll-text" },
];

// Dashboard is the index, so it matches only exactly; the rest match by prefix
// so nested detail pages keep their parent nav item highlighted.
export function isAdminNavActive(pathname, url) {
  return url === "/admin" ? pathname === "/admin" : pathname.startsWith(url);
}
