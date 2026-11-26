import { getPermissions } from "@/lib/permissions/get-permissions";

const MAX_LINKS = 6;

export async function getQuickLinks() {
  const permissions = await getPermissions();

  return (permissions || [])
    .filter((item) => item?.permissions?.view && item.level === "server" && item.url)
    .slice(0, MAX_LINKS)
    .map((item) => ({ title: item.title, url: item.url, icon: item.icon }));
}
