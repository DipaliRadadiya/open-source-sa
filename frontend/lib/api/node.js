import { api } from "@/lib/api/client";
import { RETRY_RESTART } from "@/lib/api/restart-blip";

// A site that pinned a version is unaffected: its unit holds an absolute path.
export function setDefaultNodeVersion(version) {
  return api.put("/node/default", { default: version });
}

/** Queued: returns 202 and the caller polls. Already installed returns 200. */
export function installNodeVersion(version) {
  return api.post("/node/versions", { version }, RETRY_RESTART);
}

/** `422` when a site pins it (the message names every one) or it's the default. */
export function removeNodeVersion(version) {
  return api.delete(`/node/versions/${encodeURIComponent(version)}`);
}

/** Updates npm inside that version; returns the new npm_version. */
export function updateNodeNpm(version) {
  return api.post(`/node/versions/${encodeURIComponent(version)}/npm`);
}
