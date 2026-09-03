import { api } from "@/lib/api/client";

// Each group is written on its own — the API applies them independently, so a
// single "save everything" call would report success for work it never did.
// Every write returns `{ <group>: {…refreshed values…} }`.

export function updateGeneralSettings(payload) {
  return api.put("/settings/general", payload);
}

/** `size_mb: 0` disables swap (swapoff + remove the managed file). */
export function updateSwapSettings(payload) {
  return api.put("/settings/swap", payload);
}

/**
 * SSH. The API runs `sshd -t` before reloading and opens the new port in the
 * firewall first, so a bad value can't take the daemon down — but the rule for
 * the OLD port is left behind and is the caller's to clean up.
 *
 * `422` on `password_authentication` means no SSH key exists to get back in with.
 */
export function updateSecuritySettings(payload) {
  return api.put("/settings/security", payload);
}

export function updateUpdateSettings(payload) {
  return api.put("/settings/updates", payload);
}

/**
 * A recurring restart. Disabling removes the cron file outright, so only
 * `enabled: false` needs sending in that case — the rest would describe a
 * schedule that no longer exists.
 */
export function updateRebootSchedule(payload) {
  return api.put("/settings/reboot-schedule", payload);
}

/** `404` when redis isn't installed — the group is simply absent from the read. */
/**
 * Memory settings apply immediately. A password change does not: the credential
 * the panel is currently using is the one being replaced, so the server applies
 * it AFTER answering and returns **202**, not 200.
 *
 * Callers must tell the two apart. Treating 202 as done reports success for
 * something still in flight and re-reads state that has not changed yet — which
 * reads exactly like "the password was not updated".
 */
export function updateRedisSettings(payload) {
  return api.put("/settings/redis", payload);
}

/**
 * `delay_minutes` 0–60; `0` = now. Returns 202, the server goes away shortly
 * after. The response carries `at` — the absolute moment, from the SERVER's
 * clock. Use it rather than adding the delay to `Date.now()`: the two clocks
 * drift, and this is the one value where being wrong means somebody expects a
 * restart at the wrong hour.
 */
export function rebootServer(delayMinutes = 0) {
  return api.post("/settings/reboot", { delay_minutes: delayMinutes });
}

/**
 * Whether a restart is already pending, and for when.
 *
 * Read from systemd rather than from anything the panel remembers, so a reboot
 * scheduled from a shell is not invisible here. A `500` means the panel could
 * not look, which is NOT `scheduled: false` — this is the endpoint someone
 * opens to decide whether to cancel a restart, and "no" and "I could not ask"
 * must not read alike.
 */
export function getRebootStatus() {
  return api.get("/settings/reboot");
}

/** `shutdown -c`. Cancelling when nothing is pending is a success, not a 404. */
export function cancelReboot() {
  return api.delete("/settings/reboot");
}
