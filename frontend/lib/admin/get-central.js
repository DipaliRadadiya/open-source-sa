import { read } from "@/lib/api/read";
import { centralStatusResponseSchema } from "@/lib/schemas/central";

/**
 * Whether this server is connected, and the masked token if it is.
 * Admin-only (`can:access-admin`): `status` lets the page tell a 403 from a dead API.
 */
export function getCentralStatus() {
  return read("/central/status", centralStatusResponseSchema);
}
