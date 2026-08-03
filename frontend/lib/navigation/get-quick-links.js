import { getPermissions } from "@/lib/permissions/get-permissions";

// Enough to be useful, short enough to scan. Beyond this the list stops being
// "somewhere to go" and becomes a second sidebar.
const MAX_LINKS = 6;

/**
 * Server-level destinations the current user may actually open, for the 404's
 * "where to go instead" column.
 *
 * Signed out (or no permissions) yields an empty list and the column disappears
 * — offering links that bounce to /login would make a dead end out of the page
 * meant to fix one.
 */
export async function getQuickLinks() {
  const permissions = await getPermissions();

  return (permissions || [])
    .filter((item) => item?.permissions?.view && item.level === "server" && item.url)
    .slice(0, MAX_LINKS)
    .map((item) => ({ title: item.title, url: item.url, icon: item.icon }));
}
