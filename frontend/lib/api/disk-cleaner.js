import { api } from "@/lib/api/client";

/**
 * Clean the selected categories.
 *
 * Category KEYS only — the panel resolves paths server-side, so a compromised
 * client can never name a path to delete. Synchronous: apt and journald can
 * take a while, so the caller keeps its dialog open until this resolves.
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
