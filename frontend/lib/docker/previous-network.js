export function ownNetworkName(applicationId) {
  return `sv-app-${applicationId}_default`;
}

export function offersKeepChoice({ applicationId, current, next, networks = [] }) {
  if (!next || next === (current || null)) return false;
  return networks.some((network) => network.name === ownNetworkName(applicationId));
}

export function moveOutcome({ applicationId, removeRequested, networksAfter = [] }) {
  const stillThere = networksAfter.some((network) => network.name === ownNetworkName(applicationId));
  if (!stillThere) return "removed";
  return removeRequested ? "inUse" : "kept";
}
