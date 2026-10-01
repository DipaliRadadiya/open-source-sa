import { api } from "@/lib/api/client";

// Each group is saved separately; the API applies them independently.
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
 * firewall first, but the rule for the OLD port is left for the caller to clean up.
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
 * Install waiting security updates now via unattended-upgrades (works even
 * with the automatic timer disabled).
 *
 * **202**: poll `getSecurityUpdateRun` for the outcome. **409** means one is
 * already running (carried in `security_update`); **422** means the package is
 * not installed.
 */
export function runSecurityUpdates() {
  return api.post("/settings/updates/run");
}

/**
 * The current or last run. `security_update` is null when none has ever run.
 * `output` is null without `manage`, since apt output can carry sensitive detail.
 */
export function getSecurityUpdateRun() {
  return api.get("/settings/updates/run");
}

/**
 * A recurring restart. When disabling, only `enabled: false` needs sending;
 * the cron file is removed.
 */
export function updateRebootSchedule(payload) {
  return api.put("/settings/reboot-schedule", payload);
}

/**
 * `404` when redis isn't installed. Memory settings apply immediately; a
 * password change is applied after answering and returns **202**, so callers
 * must not treat it as done or re-read state straight away.
 */
export function updateRedisSettings(payload) {
  return api.put("/settings/redis", payload);
}

/**
 * `delay_minutes` 0–60; `0` = now. Returns 202. Show the response's `at` (the
 * server's clock) rather than adding the delay to `Date.now()`.
 */
export function rebootServer(delayMinutes = 0) {
  return api.post("/settings/reboot", { delay_minutes: delayMinutes });
}

/**
 * Whether a restart is pending, read from systemd (so shell-scheduled reboots
 * show too). A `500` means the panel could not check, which is NOT
 * `scheduled: false`.
 */
export function getRebootStatus() {
  return api.get("/settings/reboot");
}

/** `shutdown -c`. Cancelling when nothing is pending is a success, not a 404. */
export function cancelReboot() {
  return api.delete("/settings/reboot");
}
