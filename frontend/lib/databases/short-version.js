/**
 * The version, without the packaging.
 *
 * Every engine buries the number in a different kind of noise:
 *   PostgreSQL  "16.15 (Ubuntu 16.15-0ubuntu0.24.04.1)"  — the number twice
 *   MariaDB     "10.11.14-MariaDB-0ubuntu0.24.04.1"      — no space at all
 *   MongoDB     "8.0.31"                                  — already clean
 *
 * Splitting on a space fixed only PostgreSQL, which is what shipping the first
 * attempt showed: MariaDB stayed three times wider than the tile it sat in. So
 * this takes the leading dotted number, which is the answer to "which version",
 * and callers keep the full string on the element's title.
 *
 * Shared rather than copied: the dashboard's runtime row shows the same three
 * engines as the databases page, and a second copy of this is a second place
 * for a fourth engine's packaging to be handled differently.
 */
export function shortVersion(version) {
  if (typeof version !== "string") return null;
  return version.match(/^\d+(?:\.\d+)*/)?.[0] ?? version.split(" ")[0] ?? null;
}
