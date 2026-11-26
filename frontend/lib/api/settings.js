import { api } from "@/lib/api/client";

export function updateGeneralSettings(payload) {
  return api.put("/settings/general", payload);
}

export function updateSwapSettings(payload) {
  return api.put("/settings/swap", payload);
}

export function updateSecuritySettings(payload) {
  return api.put("/settings/security", payload);
}

export function updateUpdateSettings(payload) {
  return api.put("/settings/updates", payload);
}

export function runSecurityUpdates() {
  return api.post("/settings/updates/run");
}

export function getSecurityUpdateRun() {
  return api.get("/settings/updates/run");
}

export function updateRebootSchedule(payload) {
  return api.put("/settings/reboot-schedule", payload);
}

// A password change returns 202 and applies afterwards; don't re-read straight away.
export function updateRedisSettings(payload) {
  return api.put("/settings/redis", payload);
}

export function rebootServer(delayMinutes = 0) {
  return api.post("/settings/reboot", { delay_minutes: delayMinutes });
}

// 500 means the panel couldn't check, not that nothing is scheduled.
export function getRebootStatus() {
  return api.get("/settings/reboot");
}

export function cancelReboot() {
  return api.delete("/settings/reboot");
}
