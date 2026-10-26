import { api } from "@/lib/api/client";

// Category keys only: paths are resolved server-side so a client can never name one.
// Synchronous and possibly slow. `keepDays`: 0 = delete all, N = keep N days; null sends nothing.
export function cleanDisk(categories, keepDays = null) {
  return api.post("/disk-cleaner/clean", { categories, ...(keepDays != null ? { keep_days: keepDays } : null) });
}

export function saveCleanerSchedule(payload) {
  return api.put("/disk-cleaner/schedule", payload);
}

export function deleteCleanerSchedule() {
  return api.delete("/disk-cleaner/schedule");
}
