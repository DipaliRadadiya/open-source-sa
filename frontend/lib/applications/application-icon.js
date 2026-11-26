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

export function imageRepository(ref) {
  let name = String(ref ?? "").trim().toLowerCase().split("@")[0];
  const slash = name.lastIndexOf("/");
  const colon = name.lastIndexOf(":");
  if (colon > slash) name = name.slice(0, colon);
  name = name.replace(/^(docker\.io|index\.docker\.io|registry-1\.docker\.io)\//, "");
  return name.replace(/^library\//, "");
}
