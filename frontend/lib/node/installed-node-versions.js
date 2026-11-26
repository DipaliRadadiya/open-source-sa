// Duplicate values make Radix render every match in the Select trigger.
export function installedNodeVersions(node) {
  const managed = (node?.versions ?? []).filter((version) => !version.status || version.status === "ready");
  const system =
    node?.system && !managed.some((version) => version.version === node.system.version)
      ? [{ ...node.system, status: "ready" }]
      : [];
  return [...managed, ...system];
}
