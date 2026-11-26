export function landingPath(catalog, fallback = "/dashboard") {
  for (const entry of catalog ?? []) {
    if (entry?.level !== "server") continue;
    if (!entry?.permissions?.view && !entry?.permissions?.manage) continue;
    if (typeof entry.url !== "string" || !entry.url.startsWith("/")) continue;

    return entry.url;
  }

  return fallback;
}
