/**
 * The leading dotted version number, without packaging noise:
 *   PostgreSQL  "16.15 (Ubuntu 16.15-0ubuntu0.24.04.1)"
 *   MariaDB     "10.11.14-MariaDB-0ubuntu0.24.04.1"
 *   MongoDB     "8.0.31"
 * Callers keep the full string in the element's title.
 */
export function shortVersion(version) {
  if (typeof version !== "string") return null;
  return version.match(/^\d+(?:\.\d+)*/)?.[0] ?? version.split(" ")[0] ?? null;
}
