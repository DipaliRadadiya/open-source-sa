import { api } from "@/lib/api/client";

/**
 * Clean the selected categories. Category KEYS only: paths are resolved
 * server-side so a client can never name a path to delete. Synchronous and
 * possibly slow; keep the dialog open until it resolves.
 */
export function cleanDisk(categories) {
  return api.post("/disk-cleaner/clean", { categories });
}

export function saveCleanerSchedule(payload) {
  return api.put("/disk-cleaner/schedule", payload);
}

export function deleteCleanerSchedule() {
  return api.delete("/disk-cleaner/schedule");
}
