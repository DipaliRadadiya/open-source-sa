import { firstDeployPending } from "../applications/settled.js";
import { canOpenApplication } from "../permissions/app-screens.js";
// Derived from the applications list, which has no certificate fields, so plain-http live sites stand in for TLS problems.
// Ordered by severity.
const KINDS = [
  {
    key: "failed",
    // The screen the link opens; a role without it gets the finding, not a dead-end link.
    screen: null,
    // NOT `failed_step`: that is also set on a running app whose last deploy failed.
    matches: (a) => a.status === "failed",
    // Its own page: that is where the failure reason and the retry live.
    href: (a) => `/applications/${a.id}`,
  },
  {
    // Old code keeps serving, but every later push inherits the failure.
    key: "deployFailed",
    screen: "app_deployment",
    matches: (a) =>
      a.status === "active" &&
      Boolean(a.failed_step) &&
      Boolean(a.repository || a.repository_url),
    href: (a) => `/applications/${a.id}/deployment`,
  },
  {
    // Same condition as the row badge; `deployed` guards a site never started.
    key: "processDown",
    screen: "app_worker",
    matches: (a) =>
      a.status === "active" &&
      !a.is_disabled &&
      Boolean(a.has_process) &&
      Boolean(a.deployed) &&
      Boolean(a.process) &&
      a.process.state !== "active" &&
      a.process.state !== "activating" &&
      !firstDeployPending(a),
    href: (a) => `/applications/${a.id}/workers`,
  },
  {
    key: "insecure",
    screen: "app_domain",
    matches: (a) =>
      a.status === "active" && !a.is_disabled && !String(a.url ?? "").startsWith("https://"),
    href: (a) => `/applications/${a.id}/domains`,
  },
  {
    key: "git",
    screen: "app_deployment",
    matches: (a) => Boolean(a.git_account_missing),
    href: (a) => `/applications/${a.id}/deployment`,
  },
];

function mayOpen(catalog, screen) {
  // Unknown grants (a failed read) keep the link: the page itself refuses if need be.
  if (catalog === null || catalog === undefined) return true;
  if (screen === null) return canOpenApplication(catalog);
  return catalog.some((entry) => entry?.level === "application" && entry.name === screen && Boolean(entry.permissions?.view || entry.permissions?.manage));
}

// `appCatalog`: the role's application-level grants (getPermissions("application")).
export function attentionFindings(applications = [], appCatalog = null) {
  const findings = [];

  for (const kind of KINDS) {
    for (const application of applications) {
      if (!kind.matches(application)) continue;
      findings.push({
        id: `${kind.key}:${application.id}`,
        kind: kind.key,
        site: application.name,
        href: mayOpen(appCatalog, kind.screen) ? kind.href(application) : null,
        // The API's own reason when it has one; it knows which step failed.
        detail: kind.key === "failed" ? (application.failed_reason_title ?? null) : null,
      });
    }
  }

  return findings;
}
