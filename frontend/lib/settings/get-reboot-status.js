import { read } from "@/lib/api/read";
import { rebootStatusResponseSchema } from "@/lib/schemas/settings";

/**
 * Whether a restart is counting down (`GET /settings/reboot`). Returns the full
 * read() result: a failed read must never be shown as "none pending".
 */
export function getRebootStatus() {
  return read("/settings/reboot", rebootStatusResponseSchema);
}
