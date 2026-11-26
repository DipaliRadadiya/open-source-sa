export function shortVersion(version) {
  if (typeof version !== "string") return null;
  return version.match(/^\d+(?:\.\d+)*/)?.[0] ?? version.split(" ")[0] ?? null;
}
