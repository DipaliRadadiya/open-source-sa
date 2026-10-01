import { read } from "@/lib/api/read";
import { rebootStatusResponseSchema } from "@/lib/schemas/settings";

/** Returns the full read() result: a failed read must never show as "none pending". */
export function getRebootStatus() {
  return read("/settings/reboot", rebootStatusResponseSchema);
}
