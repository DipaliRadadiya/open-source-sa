/**
 * Image references as people type them: `memos`, `nginx:1.27`,
 * `ghcr.io/org/app:1.2`, `localhost:5000/app`, `app@sha256:…`.
 *
 * A colon is a tag separator only after the last slash — before it, it is a
 * registry port.
 */
export function splitImageRef(ref) {
  const value = String(ref ?? "").trim();
  if (!value) return { repository: "", tag: "", digest: "" };
  const at = value.indexOf("@");
  const digest = at >= 0 ? value.slice(at + 1) : "";
  const rest = at >= 0 ? value.slice(0, at) : value;
  const colon = rest.lastIndexOf(":");
  if (colon > rest.lastIndexOf("/")) {
    return { repository: rest.slice(0, colon), tag: rest.slice(colon + 1), digest };
  }
  return { repository: rest, tag: "", digest };
}

export function joinImageRef(repository, tag) {
  return tag ? `${repository}:${tag}` : repository;
}

// Mirrors DockerSiteType's `image` rule: whitespace and shell characters reach a command line.
export const IMAGE_REF_PATTERN = /^[A-Za-z0-9][A-Za-z0-9._/:@-]*$/;

export function looksLikeImageRef(value) {
  return IMAGE_REF_PATTERN.test(String(value ?? "").trim());
}

const slugPart = (value) =>
  String(value ?? "")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");

/**
 * `<application>-<last path segment>`, the naming DS-03 uses, so one site's
 * volumes never collide with another's (`/data` is common to dozens of images).
 */
export function volumeNameFor(applicationName, path, taken = []) {
  const site = slugPart(applicationName) || "app";
  const segment = slugPart(String(path ?? "").split("/").filter(Boolean).pop()) || "data";
  const base = `${site}-${segment}`.slice(0, 120);
  let name = base;
  for (let n = 2; taken.includes(name); n += 1) name = `${base}-${n}`;
  return name;
}

/** The mounts and env rows the create request carries for the chosen image. */
export function dockerCreateExtras({ applicationName, volumes = [], env = [] }) {
  const names = [];
  const mounts = volumes
    .filter((volume) => volume.checked && volume.path)
    .map((volume) => {
      const name = volumeNameFor(applicationName, volume.path, names);
      names.push(name);
      return { volume: name, path: volume.path };
    });
  const variables = env
    .map((row) => ({ key: String(row.key ?? "").trim(), value: String(row.value ?? "") }))
    .filter((row) => row.key);
  return { mounts, env: variables };
}
