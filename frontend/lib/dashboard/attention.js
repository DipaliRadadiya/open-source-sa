import { firstDeployPending } from "../applications/settled.js";
import { canOpenApplication } from "../permissions/app-screens.js";
const KINDS = [
  {
    key: "failed",
    screen: null,
    matches: (a) => a.status === "failed",
    href: (a) => `/applications/${a.id}`,
  },
  {
    key: "deployFailed",
    screen: "app_deployment",
    matches: (a) =>
      a.status === "active" &&
      Boolean(a.failed_step) &&
      Boolean(a.repository || a.repository_url),
    href: (a) => `/applications/${a.id}/deployment`,
  },
  {
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
  if (catalog === null || catalog === undefined) return true;
  if (screen === null) return canOpenApplication(catalog);
  return catalog.some((entry) => entry?.level === "application" && entry.name === screen && Boolean(entry.permissions?.view || entry.permissions?.manage));
}

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
        detail: kind.key === "failed" ? (application.failed_reason_title ?? null) : null,
      });
    }
  }

  return findings;
}
