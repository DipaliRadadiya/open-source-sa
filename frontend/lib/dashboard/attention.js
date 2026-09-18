/**
 * What, across every site on this server, wants a person to look at it.
 *
 * Derived entirely from the applications list the dashboard already fetches —
 * no extra request. That also bounds what can be detected: the list carries no
 * certificate fields at all, so "expires in 5 days" is not knowable here. The
 * symptom that IS knowable — a live site still served over plain http — finds
 * the same site by a different route.
 *
 * Every finding names three things, because a warning answering fewer is a
 * warning that generates a support question: WHICH site, WHAT is wrong, and
 * WHERE the fix lives. `href` is the screen that actually fixes it, not the
 * site's front page — sending somebody to a dashboard to hunt for the SSL tab
 * is half an answer.
 *
 * Deliberately NOT included: paused sites, and sites still provisioning. Being
 * paused is something somebody chose, and a new site has no certificate for the
 * first few minutes of its life. Alerting on either teaches people to ignore
 * the chip, which costs you the one time it matters.
 *
 * Order is severity: a site that could be losing traffic now sits above one
 * that is merely untidy.
 */
const KINDS = [
  {
    key: "failed",
    matches: (a) => a.status === "failed" || Boolean(a.failed_step),
    // Its own page: that is where the failure reason and the retry live.
    href: (a) => `/applications/${a.id}`,
  },
  {
    key: "insecure",
    matches: (a) =>
      a.status === "active" && !a.is_disabled && !String(a.url ?? "").startsWith("https://"),
    href: (a) => `/applications/${a.id}/domains`,
  },
  {
    key: "git",
    matches: (a) => Boolean(a.git_account_missing),
    href: (a) => `/applications/${a.id}/deployment`,
  },
];

export function attentionFindings(applications = []) {
  const findings = [];

  for (const kind of KINDS) {
    for (const application of applications) {
      if (!kind.matches(application)) continue;
      findings.push({
        id: `${kind.key}:${application.id}`,
        kind: kind.key,
        site: application.name,
        href: kind.href(application),
        // The API's own sentence when it has one. It knows which step failed;
        // this file does not, and a generic line over the top of a specific
        // reason is the panel choosing to know less than it does.
        detail: kind.key === "failed" ? (application.failed_reason_title ?? null) : null,
      });
    }
  }

  return findings;
}
