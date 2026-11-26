import { api } from "@/lib/api/client";

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

export function runCronjob(id) {
  return api.post(`/cronjobs/${id}/run`);
}

export function getCronjobRuns(id, { signal } = {}) {
  return api.get(`/cronjobs/${id}/runs`, { signal });
}
