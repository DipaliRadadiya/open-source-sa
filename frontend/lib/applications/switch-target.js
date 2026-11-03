// Where switching to another application lands: the same section when that application offers it
// (Files to Files), else its dashboard. `targetItems` is the other application's own nav catalog,
// which is filtered by its site type, so Staging is only matched on a site that has it.
export function switchTarget(pathname, currentId, targetId, targetItems) {
  const prefix = `/applications/${currentId}`;
  const rest = pathname === prefix || pathname.startsWith(`${prefix}/`) ? pathname.slice(prefix.length) : "";

  const section = (Array.isArray(targetItems) ? targetItems : [])
    .filter((item) => item?.level === "application" && item?.permissions?.view && typeof item.url === "string" && item.url !== "")
    .map((item) => item.url)
    .filter((url) => rest === url || rest.startsWith(`${url}/`))
    .sort((a, b) => b.length - a.length)[0];

  // The section, not the page inside it: /files/some/folder belongs to this site alone.
  return `/applications/${targetId}${section ?? ""}`;
}
