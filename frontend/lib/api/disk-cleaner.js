import { api } from "@/lib/api/client";

export function cleanDisk(categories, keepDays = null) {
  return api.post("/disk-cleaner/clean", { categories, ...(keepDays != null ? { keep_days: keepDays } : null) });
}

export function saveCleanerSchedule(payload) {
  return api.put("/disk-cleaner/schedule", payload);
}

export function deleteCleanerSchedule() {
  return api.delete("/disk-cleaner/schedule");
}
