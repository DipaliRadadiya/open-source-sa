import { read } from "@/lib/api/read";
import { rebootStatusResponseSchema } from "@/lib/schemas/settings";

/**
 * Whether a restart is already counting down (`GET /settings/reboot`).
 *
 * Returns the full read() result rather than a boolean, because this endpoint
 * has three answers and the screen has to tell them apart: one is pending, none
 * is pending, or the panel could not look. A failed read rendered as "none"
 * would be the worst possible lie on the page someone opens to decide whether
 * to cancel a restart.
 */
export function getRebootStatus() {
  return read("/settings/reboot", rebootStatusResponseSchema);
}
