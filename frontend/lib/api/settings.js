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

// The new port is opened first, but the OLD port's rule is left for the caller.
// `422` on `password_authentication` means no SSH key exists to get back in with.
export function updateSecuritySettings(payload) {
  return api.put("/settings/security", payload);
}

export function updateUpdateSettings(payload) {
  return api.put("/settings/updates", payload);
}

// 202: poll `getSecurityUpdateRun`. 409: one is already running; 422: the
// package is not installed.
export function runSecurityUpdates() {
  return api.post("/settings/updates/run");
}

// `security_update` is null when none has run. `output` is null without `manage`.
export function getSecurityUpdateRun() {
  return api.get("/settings/updates/run");
}

// When disabling, only `enabled: false` needs sending.
export function updateRebootSchedule(payload) {
  return api.put("/settings/reboot-schedule", payload);
}

// `404` when redis isn't installed. A password change returns 202 and applies
// after answering, so do not treat it as done or re-read straight away.
export function updateRedisSettings(payload) {
  return api.put("/settings/redis", payload);
}

// `delay_minutes` 0–60. Show the response's `at` (server clock), not `Date.now()` + delay.
export function rebootServer(delayMinutes = 0) {
  return api.post("/settings/reboot", { delay_minutes: delayMinutes });
}

// Read from systemd, so shell-scheduled reboots show too. A `500` means the
// panel could not check, NOT `scheduled: false`.
export function getRebootStatus() {
  return api.get("/settings/reboot");
}

/** `shutdown -c`. Cancelling when nothing is pending is a success, not a 404. */
export function cancelReboot() {
  return api.delete("/settings/reboot");
}
