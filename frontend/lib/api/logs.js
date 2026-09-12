import { api } from "@/lib/api/client";

/**
 * The source catalog, re-read on an interval so sizes and the "written just
 * now" dots describe the box as it is rather than as it was on page load.
 */
export function listLogSources({ signal } = {}) {
  return api.get("/logs", { signal });
}

/**
 * Client-side read used for tailing, grep and reload.
 * `after` = the previous response's cursor → only newly-appended lines.
 */
export function readLog(key, { lines, grep, after, signal } = {}) {
  return api.get(`/logs/${encodeURIComponent(key)}`, {
    params: {
      ...(lines ? { lines } : null),
      ...(grep ? { grep } : null),
      ...(after != null ? { after } : null),
    },
    signal,
  });
}

// The API streams the file with Content-Disposition, so a normal navigation
// lets the browser handle it (and sends the session cookie).
export function logDownloadUrl(key) {
  return `${process.env.NEXT_PUBLIC_API_URL}/api/logs/${encodeURIComponent(key)}/download`;
}

/**
 * Empty one server log.
 *
 * Truncated server-side, never deleted — the writer keeps its file handle, so
 * nginx or MySQL carries on appending to the same inode with the same owner.
 *
 * `logs` **manage**; a viewer gets 403. **404** for a source the registry does
 * not mark `clearable` — auth.log, ufw.log, fail2ban.log, syslog, kern.log,
 * mail.log, the Let's Encrypt log and the journal. Those record what happened
 * to the machine, so the panel offers no button and the API refuses even when
 * one is asked for directly. Read `clearable` off the source rather than
 * guessing from its key.
 */
export function clearLog(key) {
  return api.delete(`/logs/${encodeURIComponent(key)}`);
}
