import { api } from "@/lib/api/client";

// Status is read live on every GET (no server cache), so re-fetching is the refresh.
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

/**
 * Install supervisord, which every worker runs under. 202 and queued.
 * `POST /workers` triggers the same install when supervisord is missing.
 */
export function installSupervisor(appId) {
  return api.post(`/applications/${appId}/workers/install-supervisor`);
}
