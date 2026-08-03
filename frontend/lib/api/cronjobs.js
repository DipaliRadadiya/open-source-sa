import { api } from "@/lib/api/client";

// Client-side cron job mutations. Callers refresh the server component after.

export function createCronjob(values) {
  return api.post("/cronjobs", values);
}

export function updateCronjob(id, values) {
  return api.put(`/cronjobs/${id}`, values);
}

export function deleteCronjob(id) {
  return api.delete(`/cronjobs/${id}`);
}

export function setCronjobActive(id, active) {
  return api.put(`/cronjobs/${id}`, { active });
}
