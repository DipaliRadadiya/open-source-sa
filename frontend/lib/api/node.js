import { api } from "@/lib/api/client";
import { RETRY_RESTART } from "@/lib/api/restart-blip";

export function setDefaultNodeVersion(version) {
  return api.put("/node/default", { default: version });
}

export function installNodeVersion(version) {
  return api.post("/node/versions", { version }, RETRY_RESTART);
}

export function removeNodeVersion(version) {
  return api.delete(`/node/versions/${encodeURIComponent(version)}`);
}

export function updateNodeNpm(version) {
  return api.post(`/node/versions/${encodeURIComponent(version)}/npm`);
}
