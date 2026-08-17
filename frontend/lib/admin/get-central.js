import { read } from "@/lib/api/read";
import { centralStatusResponseSchema } from "@/lib/schemas/central";

/**
 * Whether this server is connected, and the masked token if it is.
 *
 * Admin-only on the backend (`can:access-admin`), so a 403 here is a real
 * answer about the viewer rather than a broken endpoint — the page needs
 * `status` to tell that apart from a dead API.
 */
export function getCentralStatus() {
  return read("/central/status", centralStatusResponseSchema);
}
