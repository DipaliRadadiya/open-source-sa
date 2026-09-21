import { lowestInRange, rangeLabel, rangeUnsatisfied } from "../runtime/version-range.js";

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
 * EVERY runtime whose range rules out this server — both of them when both do.
 *
 * Was `runtimeBlock`, singular, returning on the first failing runtime. A type
 * declaring both a PHP and a Node range only ever reported the PHP one, so
 * installing a PHP version earned you the Node message on the next visit.
 * Plural here, and the caller decides how to show two.
 *
 * Each entry carries `{ runtime, range, label, installed, suggest }` — which
 * runtime, what it needs, what is here, and the version to go and install.
 */
export function runtimeBlocks({
  type,
  phpVersions,
  nodeVersions,
  phpInstallable,
  nodeInstallable,
  failed,
} = {}) {
  // A failed lookup says nothing about the server, and greying the catalogue
  // on one endpoint's wobble is a worse failure than the one this prevents.
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
         * The version to install, not the range to read.
         *
         * The card used to print n8n's range — "Needs Node 20.19 – 24" — and a
         * user went looking for Node 20.19. It is not offered: the 20 line is
         * end-of-life and the install list deliberately hides those. They gave
         * up and reported that n8n could not be installed at all.
         *
         * So the answer comes from `installable`, which is the list the Node
         * page will actually show them. Null when nothing on offer fits, and
         * that is worth saying out loud rather than papering over — it means
         * the range and this server genuinely cannot be reconciled today.
         *
         * LOWEST of those, not highest. n8n asks for `>=24` and the panel was
         * naming Node 26.9.0 simply because it was the newest thing on offer —
         * a requirement n8n does not have, on the least-tested major, for an
         * application that names 24 as its floor. The install list already
         * hides end-of-life lines, so the lowest offered is still supported.
         */
        suggest: lowestInRange(available[runtime.installableField], range),
      },
    ];
  });
}

/*
 * Marking the catalogue lives in `blockers.js` now.
 *
 * It used to be here, and a matching one sat in `database-readiness.js`. Two
 * decorators running in sequence is exactly the bug: the second deferred to
 * whatever the first had decided, so a type failing both told you about one.
 * Collecting has to happen in one place that can see every check.
 */
