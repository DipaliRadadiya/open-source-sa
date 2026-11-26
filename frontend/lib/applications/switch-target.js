export function switchTarget(pathname, currentId, targetId, targetItems) {
  const prefix = `/applications/${currentId}`;
  const rest = pathname === prefix || pathname.startsWith(`${prefix}/`) ? pathname.slice(prefix.length) : "";

  const section = (Array.isArray(targetItems) ? targetItems : [])
    .filter((item) => item?.level === "application" && item?.permissions?.view && typeof item.url === "string" && item.url !== "")
    .map((item) => item.url)
    .filter((url) => rest === url || rest.startsWith(`${url}/`))
    .sort((a, b) => b.length - a.length)[0];

  return `/applications/${targetId}${section ?? ""}`;
}
