/**
 * Which installed runtime versions a site type will actually run on.
 *
 * Each type can declare a supported range — NodeBB wants Node 22 or newer, n8n
 * wants 20.19 to 24, PrestaShop wants PHP 7.2 to 8.1 — and the create form
 * ignored it, offering every installed version for every type. Picking Node 20
 * for NodeBB was one click away, and nothing said no until the install failed.
 *
 * Both ends are INCLUSIVE and a null end is unbounded, matching
 * `AbstractSiteType::installedPhpVersionsInRange()`.
 *
 * It does NOT copy that method's fallback. The backend returns the whole
 * unfiltered list when nothing installed is in range, and this did too — which
 * is why the filter looked broken on a server with only PHP 8.4: PrestaShop
 * wants 7.2 to 8.1, nothing qualified, and the dropdown quietly offered 8.4
 * anyway. Identical to doing nothing, and the server then refuses the create.
 *
 * So an empty result is returned as an empty result, and `rangeUnsatisfied`
 * below lets the caller say so before anyone fills the form in. A select with
 * no options IS useless — the answer is a sentence naming the range and what
 * is installed, not a wrong option to pick.
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

  return list.filter((item) => versionWithin(item?.version, range));
}

/**
 * A declared range that no installed version satisfies.
 *
 * The distinction that matters: false when there is no range (most types run
 * on anything) and false when nothing is installed at all (a different
 * problem, with a different fix, already reported elsewhere). True only for
 * "this server has runtimes, and none of them will do".
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
