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
  if (max !== null && compareVersions(toPrecisionOf(version, max), max) > 0) return false;
  return true;
}

/**
 * A version cut to the number of segments the bound actually states.
 *
 * Only the UPPER bound, and only because of what a partial one means. n8n
 * declares a max of `24` and the comment beside it in the backend says "Node
 * 20.19 to 24.x inclusive" — the whole 24 line. Padding the missing segments
 * with zero turned that into `24.0.0`, so a server running the current Node
 * 24.20.0 was told it needed 20.19, which is end-of-life and deliberately not
 * offered for install. A dead end from both directions, reported by a user.
 *
 * Cutting instead of padding says what the bound says: `24` compares majors,
 * `8.1` compares major and minor, so PrestaShop's `8.1` still accepts PHP
 * 8.1.9 and still refuses 8.2. A fully-stated bound is unchanged.
 *
 * The lower bound is left alone — it needs no help. `20.19` against 20.19.3
 * already compares correctly, and cutting there would let 20.18.x in.
 */
function toPrecisionOf(version, bound) {
  const segments = String(bound).split(".").length;
  return String(version).split(".").slice(0, segments).join(".");
}

/**
 * The newest version in a list that satisfies a range, or null.
 *
 * Fed the `installable` list rather than the installed one, to answer the
 * question a blocked card leaves open: not "what does this need" but "what do
 * I go and install". Printing the range alone is what sent a user hunting for
 * Node 20.19 — the bottom of n8n's range, and a version this panel refuses to
 * install because the line is dead. Newest, because among versions that all
 * satisfy the range the supported one is the one to recommend.
 *
 * Null is a real answer and a different sentence: the range is satisfiable in
 * principle and nothing we can install satisfies it.
 */
export function highestInRange(versions, range) {
  return sortedInRange(versions, range).at(-1) ?? null;
}

/**
 * The LOWEST offered version that satisfies a range — what to install.
 *
 * Krishna, about n8n on a fresh server: "the requirement should be based on
 * n8n's actual runtime/dependency requirement, not simply whether the
 * default/latest Node.js version is installed."
 *
 * n8n declares `>=24.0.0` and the panel offered Node 26.9.0, because that was
 * the newest thing it could install. Nothing was *wrong* — 26 satisfies the
 * range — but the row read "Node 26.9.0 — the runtime n8n runs on", which
 * states a requirement n8n does not have, and it installs the least-tested
 * major for an application that names 24 as its floor.
 *
 * NOT the bottom of the declared range, which is the bug this replaced: n8n
 * once declared a floor of 20.19, the card printed it, and a reporter went
 * looking for a Node 20 that the install list deliberately hides because the
 * line is end-of-life. Both functions filter the OFFERED list first, so
 * whatever comes back is a version the Node page will actually show.
 */
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
