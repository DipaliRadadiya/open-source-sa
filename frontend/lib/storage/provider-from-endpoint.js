/**
 * Which storage service a destination actually points at.
 *
 * The API stores a `driver` — always `s3` — and the name the operator typed.
 * So a row could read "S3-compatible" for a bucket that is plainly Backblaze,
 * and the one fact everybody wants ("which provider is this?") was the one the
 * screen would not say.
 *
 * The endpoint answers it. Every provider has its own host, and the panel
 * already knows those hosts: they are the `endpointHint` values it shows while
 * you fill the form in.
 *
 * Read, never guessed. An endpoint we do not recognise returns null and the
 * row says nothing extra rather than inventing a provider — a MinIO box on a
 * company domain is unknowable from here, and wrong is worse than quiet.
 */

// Longest-matching host wins, so `s3.us-west.wasabisys.com` cannot be read as
// AWS on the strength of containing "s3.".
const HOSTS = [
  ["r2.cloudflarestorage.com", "r2"],
  ["backblazeb2.com", "b2"],
  ["wasabisys.com", "wasabi"],
  ["digitaloceanspaces.com", "spaces"],
  ["amazonaws.com", "aws"],
];

export function providerFromEndpoint(endpoint) {
  // No endpoint is AWS's own default — the form says so in as many words, and
  // it is the only provider that needs none.
  if (endpoint === null || endpoint === undefined || String(endpoint).trim() === "") {
    return "aws";
  }

  let host;
  try {
    host = new URL(String(endpoint)).hostname.toLowerCase();
  } catch {
    // Not a URL. Some destinations are stored as a bare host.
    host = String(endpoint).trim().toLowerCase().replace(/^\/+|\/+$/g, "");
    if (host.includes("/")) host = host.slice(0, host.indexOf("/"));
  }
  if (!host) return null;

  for (const [suffix, provider] of HOSTS) {
    if (host === suffix || host.endsWith(`.${suffix}`)) return provider;
  }
  return null;
}

/**
 * Where a provider's keys are created.
 *
 * Every one of these calls them something different — "API token" at
 * Cloudflare, "Application Key" at Backblaze, "Access Key" at Wasabi — so
 * "paste your access key" sends a first-time user hunting through a console
 * for a phrase that is not there. The link goes to the page that makes them.
 *
 * Null for the ones with nowhere to send anybody: a self-hosted MinIO has no
 * common console, and "other" is by definition unknown.
 */
const KEY_DOCS = {
  aws: "https://docs.aws.amazon.com/IAM/latest/UserGuide/id_credentials_access-keys.html",
  r2: "https://developers.cloudflare.com/r2/api/tokens/",
  b2: "https://www.backblaze.com/docs/cloud-storage-application-keys",
  wasabi: "https://docs.wasabi.com/v1/docs/create-a-user-and-access-key",
  spaces: "https://docs.digitalocean.com/products/spaces/how-to/manage-access/",
};

export function keyDocsUrl(provider) {
  return KEY_DOCS[provider] ?? null;
}
