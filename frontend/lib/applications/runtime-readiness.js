import { installTarget, rangeLabel, rangeUnsatisfied } from "../runtime/version-range.js";

/**
 * Whether the server's installed runtimes can run a site type (sibling of
 * `database-readiness.js`). The catalogue marks a type `available` if any PHP
 * exists, and a blank version passes the backend's `nullable` range rule then
 * falls back to the default PHP, which may be outside the range. So the type
 * is blocked here.
 */

const RUNTIMES = [
  {
    key: "php",
    rangeField: "php_version_range",
    versionsField: "phpVersions",
    installableField: "phpInstallable",
  },
  {
    key: "node",
    rangeField: "node_version_range",
    versionsField: "nodeVersions",
    installableField: "nodeInstallable",
  },
];

/**
 * Every runtime whose range rules out this server (not just the first).
 * Each entry: `{ runtime, range, label, installed, suggest }`.
 */
export function runtimeBlocks({
  type,
  phpVersions,
  nodeVersions,
  phpInstallable,
  nodeInstallable,
  failed,
} = {}) {
  // A failed lookup must not grey out the catalogue.
  if (failed) return [];

  const available = { phpVersions, nodeVersions, phpInstallable, nodeInstallable };

  return RUNTIMES.flatMap((runtime) => {
    const range = type?.[runtime.rangeField];
    const installed = available[runtime.versionsField];
    if (!rangeUnsatisfied(installed, range)) return [];

    return [
      {
        kind: "runtime",
        runtime: runtime.key,
        range,
        label: rangeLabel(range),
        installed: (Array.isArray(installed) ? installed : [])
          .map((item) => item?.version)
          .filter(Boolean),
        /*
         * A concrete version to install, picked from `installable` (what the
         * runtime page offers) by `installTarget`, since a range may start at an
         * end-of-life version that is not offered. Null when nothing fits.
         */
        ...(() => {
          const target = installTarget(available[runtime.installableField], range);
          return { suggest: target?.version ?? null, suggestEol: target?.eol ?? false };
        })(),
      },
    ];
  });
}

// Marking the catalogue happens in `blockers.js`, which sees every check.
