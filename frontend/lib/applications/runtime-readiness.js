import { installTarget, rangeLabel, rangeUnsatisfied } from "../runtime/version-range.js";

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

export function runtimeBlocks({
  type,
  phpVersions,
  nodeVersions,
  phpInstallable,
  nodeInstallable,
  failed,
} = {}) {
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
        ...(() => {
          const target = installTarget(available[runtime.installableField], range);
          return { suggest: target?.version ?? null, suggestEol: target?.eol ?? false };
        })(),
      },
    ];
  });
}

