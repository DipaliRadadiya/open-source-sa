import { read } from "@/lib/api/read";
import { rebootStatusResponseSchema } from "@/lib/schemas/settings";

export function getRebootStatus() {
  return read("/settings/reboot", rebootStatusResponseSchema);
}
