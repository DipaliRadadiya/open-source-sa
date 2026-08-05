import { api } from "@/lib/api/client";

// Status is read from systemd on every GET — nothing is cached server-side, so
// re-fetching this is the refresh.
export function listWorkers(appId, { signal } = {}) {
  return api.get(`/applications/${appId}/workers`, { signal });
}

export function createWorker(appId, values) {
  return api.post(`/applications/${appId}/workers`, values);
}

export function updateWorker(appId, workerId, values) {
  return api.put(`/applications/${appId}/workers/${workerId}`, values);
}

export function deleteWorker(appId, workerId) {
  return api.delete(`/applications/${appId}/workers/${workerId}`);
}

// action: start | stop | restart. Restart is graceful per kind server-side
// (queue:restart, horizon:terminate, or a plain unit restart for custom).
export function runWorkerAction(appId, workerId, action) {
  return api.post(`/applications/${appId}/workers/${workerId}/${action}`);
}
