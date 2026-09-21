/**
 * Where to send someone who has just arrived with no page in mind.
 *
 * It was always `/dashboard`. That is the right answer for an administrator
 * and a wall for everybody else: a role holding only `application.view` and
 * `database.view` logged in and the first thing the panel said was "You don't
 * have access to the dashboard", with two perfectly usable pages sitting in
 * the sidebar beside the message.
 *
 * The answer comes from the same catalog the sidebar is built from, in the
 * order the backend publishes it, so the landing page is by construction one
 * the caller can open — and it follows a role edit without anyone
 * remembering to update a list here.
 *
 * Server level only. An `application` entry's `url` is a fragment like
 * `/domains` that means nothing without an application id.
 */
export function landingPath(catalog, fallback = "/dashboard") {
  for (const entry of catalog ?? []) {
    if (entry?.level !== "server") continue;
    if (!entry?.permissions?.view && !entry?.permissions?.manage) continue;
    // Some entries are real permissions with no screen of their own.
    if (typeof entry.url !== "string" || !entry.url.startsWith("/")) continue;

    return entry.url;
  }

  /*
   * Nothing at all — a role with no server-level view anywhere. The fallback
   * is still the dashboard, which refuses in place and explains why. Sending
   * them to /login instead would read as "your password is wrong", and
   * throwing here would turn a misconfigured role into a crash.
   */
  return fallback;
}
