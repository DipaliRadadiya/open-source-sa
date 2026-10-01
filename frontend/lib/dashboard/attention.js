// Derived from the applications list, which has no certificate fields, so plain-http live sites stand in for TLS problems.
// Ordered by severity.
const KINDS = [
  {
    key: "failed",
    // NOT `failed_step`: that is also set on a running app whose last deploy failed.
    matches: (a) => a.status === "failed",
    // Its own page: that is where the failure reason and the retry live.
    href: (a) => `/applications/${a.id}`,
  },
  {
    // Old code keeps serving, but every later push inherits the failure.
    key: "deployFailed",
    matches: (a) =>
      a.status === "active" &&
      Boolean(a.failed_step) &&
      Boolean(a.repository || a.repository_url),
    href: (a) => `/applications/${a.id}/deployment`,
  },
  {
    // Same condition as the row badge; `deployed` guards a site never started.
    key: "processDown",
    matches: (a) =>
      a.status === "active" &&
      !a.is_disabled &&
      Boolean(a.has_process) &&
      Boolean(a.deployed) &&
      Boolean(a.process) &&
      a.process.state !== "active" &&
      a.process.state !== "activating",
    href: (a) => `/applications/${a.id}/workers`,
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
        // The API's own reason when it has one; it knows which step failed.
        detail: kind.key === "failed" ? (application.failed_reason_title ?? null) : null,
      });
    }
  }

  return findings;
}
