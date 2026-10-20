/**
 * The catalog icon for an application: its own 1-Click Recipe's, or — for a site
 * deployed straight from an image — the recipe that ships that same image (a
 * `neosmemo/memos` site shows the Memos logo). Null otherwise, and the type's
 * logo (the Docker whale for image and compose sites) is used instead.
 */
export function applicationIconDataUri(application, siteTypes = []) {
  const own = siteTypes.find((type) => type.name === application?.site_type)?.recipe?.icon_data_uri;
  if (own) return own;

  const image = application?.origin?.image;
  if (!image) return null;

  const repository = imageRepository(image);
  const match = siteTypes.find(
    (type) =>
      type.recipe?.icon_data_uri &&
      (type.recipe.images ?? []).some((candidate) => candidate.role === "app" && imageRepository(candidate.ref) === repository),
  );
  return match?.recipe?.icon_data_uri ?? null;
}

/** `docker.io/library/nginx:1.27@sha256:…` → `nginx`; `ghcr.io/org/app:1` → `ghcr.io/org/app`. */
export function imageRepository(ref) {
  let name = String(ref ?? "").trim().toLowerCase().split("@")[0];
  const slash = name.lastIndexOf("/");
  const colon = name.lastIndexOf(":");
  if (colon > slash) name = name.slice(0, colon);
  name = name.replace(/^(docker\.io|index\.docker\.io|registry-1\.docker\.io)\//, "");
  return name.replace(/^library\//, "");
}
