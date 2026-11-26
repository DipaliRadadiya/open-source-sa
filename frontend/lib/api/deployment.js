import { api } from "@/lib/api/client";

export function updateWebhook(id, payload) {
  return api.put(`/applications/${id}/webhook`, payload);
}

export function readApplication(id) {
  return api.get(`/applications/${id}`);
}

export function fetchLatestDeployment(id) {
  return api.get(`/applications/${id}/deployments/latest`);
}

export function fetchDeployment(id, deploymentId) {
  return api.get(`/applications/${id}/deployments/${deploymentId}`);
}

export function updateDeploySettings(id, payload) {
  return api.put(`/applications/${id}/deployment-settings`, payload);
}
