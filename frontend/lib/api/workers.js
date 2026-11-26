import { api } from "@/lib/api/client";

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

export function runWorkerAction(appId, workerId, action) {
  return api.post(`/applications/${appId}/workers/${workerId}/${action}`);
}

export function installSupervisor(appId) {
  return api.post(`/applications/${appId}/workers/install-supervisor`);
}
