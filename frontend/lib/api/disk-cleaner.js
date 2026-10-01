import { api } from "@/lib/api/client";

// Category keys only: paths are resolved server-side so a client can never name one.
// Synchronous and possibly slow.
export function cleanDisk(categories) {
  return api.post("/disk-cleaner/clean", { categories });
}

export function saveCleanerSchedule(payload) {
  return api.put("/disk-cleaner/schedule", payload);
}

export function deleteCleanerSchedule() {
  return api.delete("/disk-cleaner/schedule");
}
