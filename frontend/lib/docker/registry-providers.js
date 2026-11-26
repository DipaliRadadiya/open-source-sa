/**
 * The common registries and what each calls its two values (Docker Hub's host is `docker.io`,
 * GHCR needs a classic PAT with `read:packages`, GitLab a deploy token). Client-side only: the
 * server stores the address, which already identifies the registry.
 */
export const REGISTRY_PROVIDERS = [
  {
    id: "dockerhub",
    // The value the address field is filled with. `docker.io` rather than the v1
    // index URL the daemon actually keys on: this is the field the user reads
    // back, and the panel shows the real key beside the saved row.
    address: "docker.io",
    // Whether the address field is theirs to edit. Fixed for the hosted ones,
    // because a typo there is silently ignored at pull time.
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
    // Editable: self-managed GitLab has its own registry host.
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

/**
 * Which provider a saved address looks like, so editing a row opens on the right
 * instructions instead of defaulting to Docker Hub and mislabelling it.
 *
 * Matched on the host the model would normalise to, not on a string compare, so
 * `https://ghcr.io/` lands on GHCR like `ghcr.io` does.
 */
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

// Registries whose tokens expire within hours (ECR, Artifact Registry, ACR). The panel
// doesn't refresh them, so the form says so.
export function issuesTemporaryTokens(address) {
  const host = String(address ?? "").toLowerCase();

  return (
    host.includes(".dkr.ecr.") ||
    host.endsWith("-docker.pkg.dev") ||
    host.endsWith("gcr.io") ||
    host.endsWith(".azurecr.io")
  );
}
