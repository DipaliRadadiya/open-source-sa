// Both ends INCLUSIVE, a null end unbounded, matching `installedPhpVersionsInRange()`;
// unlike it, an empty result stays empty.

/** `[{ version }]` filtered to a `{ min, max }` range; untouched without one. */
export function versionsInRange(versions, range) {
  const list = Array.isArray(versions) ? versions : [];
  const min = range?.min ?? null;
  const max = range?.max ?? null;
  if (min === null && max === null) return list;

  return list.filter((item) => versionWithin(item?.version, range));
}

/** False with no range, and when nothing is installed (reported elsewhere). */
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

// Upper bound only: a max of `24` means the whole 24.x line, and `8.1` accepts 8.1.9
// but not 8.2.
function toPrecisionOf(version, bound) {
  const segments = String(bound).split(".").length;
  return String(version).split(".").slice(0, segments).join(".");
}

/** The newest version in a list that satisfies a range, or null. */
export function highestInRange(versions, range) {
  return sortedInRange(versions, range).at(-1) ?? null;
}

// The LOWEST supported version in range (the newest major may be untested by the app);
// if none is supported (PHP lists EOL lines), the highest. Returns `{ version, eol }`.
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

// A subset of PHP's `version_compare`: missing segments count as zero ("22" == "22.0"),
// and non-numeric ones compare as zero rather than throwing.
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
