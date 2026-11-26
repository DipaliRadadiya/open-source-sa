const DESTINATIONS = {
  certificate: (id) => `/applications/${id}/domains?tab=ssl`,
  dns: (id) => `/applications/${id}/domains`,
  worker: (id) => `/applications/${id}/workers`,
  php_eol: (id) => `/applications/${id}/php`,
  deploy_failed: (id) => `/applications/${id}/deployment`,
  disk: () => "/disk-cleaner",
};

export function issueItems(issues, applicationId, actionLabel) {
  const rows = Array.isArray(issues) ? issues : [];

  return rows
    .filter((issue) => issue?.message)
    .slice()
    .sort((a, b) => Number(b.severity === "critical") - Number(a.severity === "critical"))
    .map((issue, index) => {
      const to = DESTINATIONS[issue.type];
      return {
        key: `issue-${issue.type}-${index}`,
        label: issue.message,
        action: to ? actionLabel(issue.type) : undefined,
        href: to ? to(applicationId) : undefined,
      };
    });
}

export function localKeysSupersededBy(issues) {
  const types = new Set((Array.isArray(issues) ? issues : []).map((issue) => issue?.type));
  const superseded = new Set();
  if (types.has("certificate")) superseded.add("ssl");
  return superseded;
}
