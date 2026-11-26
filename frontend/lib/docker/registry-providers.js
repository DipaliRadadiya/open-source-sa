export const REGISTRY_PROVIDERS = [
  {
    id: "dockerhub",
    address: "docker.io",
    addressFixed: true,
    usernamePlaceholder: "your-docker-id",
    tokenPlaceholder: "dckr_pat_…",
    docsUrl: "https://app.docker.com/settings/personal-access-tokens",
  },
  {
    id: "ghcr",
    address: "ghcr.io",
    addressFixed: true,
    usernamePlaceholder: "your-github-username",
    tokenPlaceholder: "ghp_…",
    docsUrl: "https://github.com/settings/tokens",
  },
  {
    id: "gitlab",
    address: "registry.gitlab.com",
    addressFixed: false,
    usernamePlaceholder: "deploy-token-username",
    tokenPlaceholder: "gldt-…",
    docsUrl: "https://docs.gitlab.com/user/project/deploy_tokens/",
  },
  {
    id: "other",
    address: "",
    addressFixed: false,
    usernamePlaceholder: "username",
    tokenPlaceholder: "token or password",
    docsUrl: null,
  },
];

export function registryProvider(id) {
  return (
    REGISTRY_PROVIDERS.find((provider) => provider.id === id) ??
    REGISTRY_PROVIDERS[0]
  );
}

export function providerForAddress(address) {
  const host = String(address ?? "")
    .trim()
    .replace(/^https?:\/\//i, "")
    .replace(/\/.*$/, "")
    .toLowerCase();

  if (
    [
      "docker.io",
      "index.docker.io",
      "registry-1.docker.io",
      "https://index.docker.io/v1/",
    ].includes(host)
  ) {
    return "dockerhub";
  }
  if (host === "ghcr.io") return "ghcr";
  if (host.endsWith("gitlab.com")) return "gitlab";

  return "other";
}

export function issuesTemporaryTokens(address) {
  const host = String(address ?? "").toLowerCase();

  return (
    host.includes(".dkr.ecr.") ||
    host.endsWith("-docker.pkg.dev") ||
    host.endsWith("gcr.io") ||
    host.endsWith(".azurecr.io")
  );
}
