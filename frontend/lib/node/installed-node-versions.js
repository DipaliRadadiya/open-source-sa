/**
 * Node versions a site can run on: the panel-managed ones that are ready, plus the
 * system Node when the panel does not already manage that version (duplicate values
 * make Radix render every match into a Select trigger).
 */
export function installedNodeVersions(node) {
  const managed = (node?.versions ?? []).filter((version) => !version.status || version.status === "ready");
  const system =
    node?.system && !managed.some((version) => version.version === node.system.version)
      ? [{ ...node.system, status: "ready" }]
      : [];
  return [...managed, ...system];
}
