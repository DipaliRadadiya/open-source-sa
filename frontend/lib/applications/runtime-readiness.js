import { rangeLabel, rangeUnsatisfied } from "../runtime/version-range.js";

/**
 * Whether this server's installed runtimes can actually run a site type.
 *
 * The sibling of `database-readiness.js`, and the same gap one runtime along.
 * A type can declare the versions it runs on — PrestaShop 7.2 to 8.1, Craft
 * 8.2+, NodeBB Node 22+ — and the catalogue reports it `available` as long as
 * SOME PHP exists, without asking whether any of it is a version this type can
 * use. On a server with only PHP 8.4, PrestaShop looked creatable, the version
 * picker offered 8.4, and the refusal arrived after the form was filled in.
 *
 * Worse, it does not always arrive. The backend's range rule is `nullable`, so
 * leaving the version blank passes validation — and the installer then falls
 * back to `config('server.default_php_version')`, which is the very version the
 * range excludes. That is a PrestaShop provisioned onto PHP 8.4 with a green
 * form and no error anywhere. Blocking the type is what closes it from here.
 */

const RUNTIMES = [
  { key: "php", rangeField: "php_version_range", versionsField: "phpVersions" },
  { key: "node", rangeField: "node_version_range", versionsField: "nodeVersions" },
];

/**
 * Why this type's runtime rules out this server, or null when they do not.
 *
 * Returns `{ runtime, range, installed }` — the caller needs all three to
 * write a sentence worth reading: which runtime, what it needs, what is here.
 */
export function runtimeBlock({ type, phpVersions, nodeVersions, failed } = {}) {
  // A failed lookup says nothing about the server, and greying the catalogue
  // on one endpoint's wobble is a worse failure than the one this prevents.
  if (failed) return null;
  // Already blocked, with the backend's own reason. Two answers to one
  // question is how they start to disagree.
  if (type?.available === false) return null;

  const available = { phpVersions, nodeVersions };

  for (const runtime of RUNTIMES) {
    const range = type?.[runtime.rangeField];
    const installed = available[runtime.versionsField];
    if (!rangeUnsatisfied(installed, range)) continue;

    return {
      runtime: runtime.key,
      range,
      label: rangeLabel(range),
      installed: (Array.isArray(installed) ? installed : [])
        .map((item) => item?.version)
        .filter(Boolean),
    };
  }

  return null;
}

/**
 * The catalogue with runtime-blocked types marked, in the shape the picker
 * already renders.
 *
 * Reuses `available` / `unavailable_reason` / `unavailable_code` for the same
 * reason the database check does: the grid, the greying and the reason line
 * all exist, and a second mechanism beside them is how one gets forgotten.
 *
 * `unavailable_code: "runtime"` is the backend's own value for this case, so a
 * card blocked here behaves exactly like one the backend blocked — including
 * the offer to install the runtime, which is the right action here too.
 */
export function withRuntimeAvailability(siteTypes, runtimes, reasonFor) {
  return (Array.isArray(siteTypes) ? siteTypes : []).map((type) => {
    const block = runtimeBlock({ type, ...(runtimes ?? {}) });
    if (block === null) return type;

    return {
      ...type,
      available: false,
      unavailable_code: "runtime",
      unavailable_reason: reasonFor(block),
      // Not `installable_runtime`: that offers to install the runtime this
      // server lacks entirely. Here the runtime IS installed — the wrong
      // version of it — and the fix is to add a version, which is a different
      // screen and a different sentence. The reason carries the link.
      installable_runtime: null,
    };
  });
}
