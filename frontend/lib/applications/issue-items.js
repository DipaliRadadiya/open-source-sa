/**
 * `GET /applications/{id}/issues` as attention-strip rows. The message arrives
 * translated and is shown as sent: it carries numbers this side does not have.
 */

// Where each kind of problem is fixed; unmapped types render the label alone.
const DESTINATIONS = {
  certificate: (id) => `/applications/${id}/domains?tab=ssl`,
  dns: (id) => `/applications/${id}/domains`,
  worker: (id) => `/applications/${id}/workers`,
  php_eol: (id) => `/applications/${id}/php`,
  deploy_failed: (id) => `/applications/${id}/deployment`,
  // Server-level: the disk is shared by every site.
  disk: () => "/disk-cleaner",
};

/**
 * `[{ type, severity, message }]` → `[{ key, label, action, href }]`, critical
 * first since the strip has no other ranking.
 */
export function issueItems(issues, applicationId, actionLabel) {
  const rows = Array.isArray(issues) ? issues : [];

  return rows
    .filter((issue) => issue?.message)
    .slice()
    .sort((a, b) => Number(b.severity === "critical") - Number(a.severity === "critical"))
    .map((issue, index) => {
      const to = DESTINATIONS[issue.type];
      return {
        // The type is not unique, so the index is part of the key.
        key: `issue-${issue.type}-${index}`,
        label: issue.message,
        action: to ? actionLabel(issue.type) : undefined,
        href: to ? to(applicationId) : undefined,
      };
    });
}

/**
 * Locally computed rows that a server issue of the same type supersedes; the
 * server's answer wins.
 */
export function localKeysSupersededBy(issues) {
  const types = new Set((Array.isArray(issues) ? issues : []).map((issue) => issue?.type));
  const superseded = new Set();
  if (types.has("certificate")) superseded.add("ssl");
  return superseded;
}
