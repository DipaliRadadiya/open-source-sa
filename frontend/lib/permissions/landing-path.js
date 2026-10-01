/**
 * Where to send someone who arrives with no page in mind: the first
 * server-level screen in the permission catalog (the sidebar's source) that
 * they can open, so a restricted role does not land on a refused dashboard.
 *
 * Server level only: an `application` entry's `url` (e.g. `/domains`) needs an
 * application id.
 */
export function landingPath(catalog, fallback = "/dashboard") {
  for (const entry of catalog ?? []) {
    if (entry?.level !== "server") continue;
    if (!entry?.permissions?.view && !entry?.permissions?.manage) continue;
    // Some entries are real permissions with no screen of their own.
    if (typeof entry.url !== "string" || !entry.url.startsWith("/")) continue;

    return entry.url;
  }

  // No server-level view at all: the dashboard explains the refusal, whereas
  // /login would look like a bad password and throwing would crash.
  return fallback;
}
