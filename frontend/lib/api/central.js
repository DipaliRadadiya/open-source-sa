import { api } from "@/lib/api/client";

/**
 * Mint a token; the response is the only copy that will ever exist.
 *
 * Also rotates: on a live connection the old token stops working immediately,
 * so the calling screen must treat a second press as breaking.
 */
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
