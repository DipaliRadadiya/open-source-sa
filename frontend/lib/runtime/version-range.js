/**
 * Which installed runtime versions a site type will run on, given its declared
 * range. Both ends are INCLUSIVE and a null end is unbounded, matching
 * `AbstractSiteType::installedPhpVersionsInRange()`.
 *
 * Unlike that method, an empty result stays empty (no fallback to the full
 * list); `rangeUnsatisfied` lets the caller explain it.
 */

/** `[{ version }]` filtered to a `{ min, max }` range; untouched without one. */
export function versionsInRange(versions, range) {
  const list = Array.isArray(versions) ? versions : [];
  const min = range?.min ?? null;
  const max = range?.max ?? null;
  if (min === null && max === null) return list;

  return list.filter((item) => versionWithin(item?.version, range));
}

/**
 * True only when versions are installed and none satisfies the declared range.
 * False with no range, and false when nothing is installed (reported elsewhere).
 */
export function rangeUnsatisfied(versions, range) {
  const list = Array.isArray(versions) ? versions : [];
  if (list.length === 0) return false;
  if ((range?.min ?? null) === null && (range?.max ?? null) === null) return false;
  return versionsInRange(list, range).length === 0;
}

/** A range as a phrase: "7.2 – 8.1", "8.2+", "up to 8.1". */
export function rangeLabel(range, { upTo } = {}) {
  const min = range?.min ?? null;
  const max = range?.max ?? null;
  if (min !== null && max !== null) return `${min} – ${max}`;
  if (min !== null) return `${min}+`;
  if (max !== null) return upTo ? `${upTo} ${max}` : `≤ ${max}`;
  return "";
}

/** Whether one version satisfies a range, both ends inclusive. */
export function versionWithin(version, range) {
  if (typeof version !== "string" || version === "") return false;
  const min = range?.min ?? null;
  const max = range?.max ?? null;
  if (min !== null && compareVersions(version, min) < 0) return false;
  if (max !== null && compareVersions(toPrecisionOf(version, max), max) > 0) return false;
  return true;
}

/**
 * A version cut to the number of segments the bound states, for the UPPER
 * bound only: a max of `24` means the whole 24.x line, and `8.1` accepts 8.1.9
 * but not 8.2. The lower bound needs no cutting.
 */
function toPrecisionOf(version, bound) {
  const segments = String(bound).split(".").length;
  return String(version).split(".").slice(0, segments).join(".");
}

/** The newest version in a list that satisfies a range, or null. */
export function highestInRange(versions, range) {
  return sortedInRange(versions, range).at(-1) ?? null;
}

/**
 * The version to install for a type that declares a range, chosen from the
 * `installable` list (what the runtime page offers): the LOWEST supported
 * version in range (the newest major may be untested by the app); if none in
 * range is supported (the PHP list includes EOL lines), the highest in range.
 * Returns `{ version, eol }` so the caller can mention end-of-life.
 */
export function installTarget(versions, range) {
  const candidates = versionsInRange(Array.isArray(versions) ? versions : [], range)
    .filter((item) => typeof item?.version === "string" && item.version !== "")
    .sort((a, b) => compareVersions(a.version, b.version));
  if (candidates.length === 0) return null;

  const supported = candidates.filter((item) => item?.lifecycle?.status !== "eol");
  const chosen = supported[0] ?? candidates[candidates.length - 1];

  return { version: chosen.version, eol: chosen?.lifecycle?.status === "eol" };
}

/** The lowest offered version in range, as a bare string. */
export function lowestInRange(versions, range) {
  return sortedInRange(versions, range)[0] ?? null;
}

/** Versions in range, ascending. */
function sortedInRange(versions, range) {
  return versionsInRange(versions, range)
    .map((item) => item?.version)
    .filter((version) => typeof version === "string" && version !== "")
    .sort(compareVersions);
}

/**
 * Segment-wise numeric comparison (the subset of PHP's `version_compare` used
 * here). Missing segments count as zero ("22" == "22.0"); non-numeric ones
 * compare as zero rather than throwing.
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
