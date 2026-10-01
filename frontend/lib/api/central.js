import { api } from "@/lib/api/client";

// The response is the only copy of the token. Also rotates: the old token stops
// working immediately, so a second press is breaking.
export function enableCentral() {
  return api.post("/central/enable");
}

/** Masked status only. This can never return the raw token. */
export function getCentralStatus({ signal } = {}) {
  return api.get("/central/status", { signal });
}

/** Revoke. Access ends on the next request, not at the end of a session. */
export function disableCentral() {
  return api.delete("/central");
}
