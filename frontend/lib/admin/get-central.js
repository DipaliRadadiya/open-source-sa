import { read } from "@/lib/api/read";
import { centralStatusResponseSchema } from "@/lib/schemas/central";

// Admin-only: `status` lets the page tell a 403 from a dead API.
export function getCentralStatus() {
  return read("/central/status", centralStatusResponseSchema);
}
