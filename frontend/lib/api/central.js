import { api } from "@/lib/api/client";

/**
 * Mint a token, and hand back the only copy that will ever exist.
 *
 * Also the rotate path: called while a connection is live, it replaces the
 * existing token and the old one stops working immediately. The API draws no
 * distinction between the two, so the calling screen has to — a second press
 * of this is a breaking change to whatever is already connected.
 */
export function enableCentral() {
  return api.post("/central/enable");
}

/** Masked status only. This can never return the raw token. */
export function getCentralStatus({ signal } = {}) {
  return api.get("/central/status", { signal });
}

/** Revoke. The guard compares every request against the stored value, so
 *  access ends on the next call rather than at the end of a session. */
export function disableCentral() {
  return api.delete("/central");
}
