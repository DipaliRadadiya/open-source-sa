import { getPermissions } from "@/lib/permissions/get-permissions";

// Enough to be useful, short enough to scan.
const MAX_LINKS = 6;

/**
 * Server-level destinations the current user may open, for the 404's "where to
 * go instead" column. Signed out (or no permissions) yields an empty list, so
 * no link bounces to /login.
 */
export async function getQuickLinks() {
  const permissions = await getPermissions();

  return (permissions || [])
    .filter((item) => item?.permissions?.view && item.level === "server" && item.url)
    .slice(0, MAX_LINKS)
    .map((item) => ({ title: item.title, url: item.url, icon: item.icon }));
}
