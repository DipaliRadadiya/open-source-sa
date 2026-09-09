/**
 * Which installed runtime versions a site type will actually run on.
 *
 * Each type can declare a supported range — NodeBB wants Node 22 or newer, n8n
 * wants 20.19 to 24, PrestaShop wants PHP 7.2 to 8.1 — and the create form
 * ignored it, offering every installed version for every type. Picking Node 20
 * for NodeBB was one click away, and nothing said no until the install failed.
 *
 * Mirrors `AbstractSiteType::installedPhpVersionsInRange()`: both ends are
 * INCLUSIVE, a null end is unbounded, and a range that excludes everything
 * installed falls back to the full list rather than emptying the dropdown. That
 * last rule is the important one — a select with no options tells the reader
 * nothing, while a version the server then refuses at least names the problem.
 */

/**
 * `[{ version }]` filtered to a `{ min, max }` range.
 *
 * The list is returned untouched when there is no range, which is the common
 * case: most types run on anything installed.
 */
export function versionsInRange(versions, range) {
  const list = Array.isArray(versions) ? versions : [];
  const min = range?.min ?? null;
  const max = range?.max ?? null;
  if (min === null && max === null) return list;

  const within = list.filter((item) => versionWithin(item?.version, range));
  // Never leave nothing to choose from — see the note above.
  return within.length > 0 ? within : list;
}

/** Whether one version satisfies a range, both ends inclusive. */
export function versionWithin(version, range) {
  if (typeof version !== "string" || version === "") return false;
  const min = range?.min ?? null;
  const max = range?.max ?? null;
  if (min !== null && compareVersions(version, min) < 0) return false;
  if (max !== null && compareVersions(version, max) > 0) return false;
  return true;
}

/**
 * Segment-wise numeric comparison, the part of PHP's `version_compare` these
 * versions actually use.
 *
 * A missing segment counts as zero, so "22" and "22.0" are equal and "20.19"
 * sorts above "20" — which is exactly the n8n lower bound. Anything
 * non-numeric compares as zero rather than throwing: a version string we
 * cannot read must not decide a field is unusable.
 */
export function compareVersions(a, b) {
  const left = String(a).split(".");
  const right = String(b).split(".");

  for (let i = 0; i < Math.max(left.length, right.length); i += 1) {
    const x = Number.parseInt(left[i] ?? "0", 10) || 0;
    const y = Number.parseInt(right[i] ?? "0", 10) || 0;
    if (x !== y) return x < y ? -1 : 1;
  }
  return 0;
}
