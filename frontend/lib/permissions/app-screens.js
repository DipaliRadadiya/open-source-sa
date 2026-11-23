// An application's screens come from the API's catalog (`level=application`), each with its
// path under /applications/{id} ("" is the dashboard). Plain module: server pages and tests use it.

function canView(item) {
  return item?.level === "application" && Boolean(item.permissions?.view || item.permissions?.manage);
}

// The path of the first screen the role may open, in catalog (sidebar) order; "" is the
// dashboard. null when the role may open none of them.
export function firstAllowedScreen(catalog) {
  const item = (catalog ?? []).find((entry) => canView(entry) && typeof entry.url === "string");
  return item ? item.url : null;
}

// Whether a role can open any screen of an application at all. Unknown (a failed read) is
// true, so a hiccup never hides the way in.
export function canOpenApplication(catalog) {
  if (catalog === null || catalog === undefined) return true;
  return catalog.some((entry) => canView(entry) && typeof entry.url === "string");
}
