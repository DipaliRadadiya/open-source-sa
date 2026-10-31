// A move off the network an application was created on can remove that network (the
// backend's default) or keep it (`remove_previous_network: false`, Dipali 30 Oct).

/** The network Compose made for this application. */
export function ownNetworkName(applicationId) {
  return `sv-app-${applicationId}_default`;
}

/**
 * Whether to offer "Remove the app's old network": only on a move to a different, named
 * network while the application's own network still exists. Moving back to its own network
 * (`null`/empty) never removes it.
 */
export function offersKeepChoice({ applicationId, current, next, networks = [] }) {
  if (!next || next === (current || null)) return false;
  return networks.some((network) => network.name === ownNetworkName(applicationId));
}

/** Which message the outcome deserves, read from the networks after the save. */
export function moveOutcome({ applicationId, removeRequested, networksAfter = [] }) {
  const stillThere = networksAfter.some((network) => network.name === ownNetworkName(applicationId));
  if (!stillThere) return "removed";
  return removeRequested ? "inUse" : "kept";
}
