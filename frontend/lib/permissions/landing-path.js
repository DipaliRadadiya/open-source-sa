// First server-level screen the role can open. Server level only: an `application`
// entry's `url` needs an application id.
export function landingPath(catalog, fallback = "/dashboard") {
  for (const entry of catalog ?? []) {
    if (entry?.level !== "server") continue;
    if (!entry?.permissions?.view && !entry?.permissions?.manage) continue;
    // Some entries are real permissions with no screen of their own.
    if (typeof entry.url !== "string" || !entry.url.startsWith("/")) continue;

    return entry.url;
  }

  // The dashboard explains the refusal; /login would look like a bad password.
  return fallback;
}
