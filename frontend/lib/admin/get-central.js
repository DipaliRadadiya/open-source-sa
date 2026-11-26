import { read } from "@/lib/api/read";
import { centralStatusResponseSchema } from "@/lib/schemas/central";

export function getCentralStatus() {
  return read("/central/status", centralStatusResponseSchema);
}
