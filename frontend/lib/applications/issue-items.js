/**
 * The server's own list of what is wrong with a site, as attention-strip rows.
 *
 * `GET /applications/{id}/issues` has existed for a while and nothing called
 * it. The page worked its problems out itself instead — which can only see
 * what the page happens to have fetched, so four of the six the server checks
 * were invisible here: a certificate about to expire, DNS not pointing at this
 * server, a PHP version past end of life, and the disk filling up.
 *
 * The message arrives translated. It is the server's sentence, shown as sent
 * rather than re-worded here: it carries numbers this side does not have —
 * days remaining, percent used — and a second phrasing of the same fact is how
 * two screens end up disagreeing about it.
 */

// Where each kind of problem is actually fixed. A row with nowhere to go is
// still worth showing; the strip renders the label alone.
const DESTINATIONS = {
  certificate: (id) => `/applications/${id}/domains?tab=ssl`,
  dns: (id) => `/applications/${id}/domains`,
  worker: (id) => `/applications/${id}/workers`,
  php_eol: (id) => `/applications/${id}/php`,
  deploy_failed: (id) => `/applications/${id}/deployment`,
  // Server-level, not this site's: the disk is shared by every site on the box.
  disk: () => "/disk-cleaner",
};

/**
 * `[{ type, severity, message }]` → `[{ key, label, action, href }]`.
 *
 * Critical first. The strip is one amber band and does not rank within itself,
 * so the order is the only thing saying "this one is already broken, that one
 * is a risk" — and an expired certificate under a PHP-version notice reads as
 * equally optional.
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
        // The type is not unique — two DNS problems can arrive together — so
        // the index joins it rather than a bare type that React would warn on.
        key: `issue-${issue.type}-${index}`,
        label: issue.message,
        action: to ? actionLabel(issue.type) : undefined,
        href: to ? to(applicationId) : undefined,
      };
    });
}

/**
 * Which locally computed rows the server has already spoken for.
 *
 * The page's own "no certificate" row and the server's "certificate expires in
 * six days" are about the same thing, and showing both makes the strip argue
 * with itself. The server wins: it can see the certificate, and this side is
 * inferring from what a fetch happened to return.
 */
export function localKeysSupersededBy(issues) {
  const types = new Set((Array.isArray(issues) ? issues : []).map((issue) => issue?.type));
  const superseded = new Set();
  if (types.has("certificate")) superseded.add("ssl");
  return superseded;
}
