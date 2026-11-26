import { api } from "@/lib/api/client";

const BASE = "/integrations/git";

export function getAccountStatuses({ signal } = {}) {
  return api.get(`${BASE}/accounts/status`, { signal });
}

export function connectAccount(payload) {
  return api.post(`${BASE}/accounts`, payload);
}

export function updateAccount(id, payload) {
  return api.put(`${BASE}/accounts/${id}`, payload);
}

export function testAccount(id) {
  return api.post(`${BASE}/accounts/${id}/test`);
}

export function disconnectAccount(id) {
  return api.delete(`${BASE}/accounts/${id}`);
}

export function relinkGitAccount(applicationId, payload) {
  return api.put(`/applications/${applicationId}/git-account`, payload);
}

export function getRepositories(accountId, params = {}) {
  return api.get(`${BASE}/accounts/${accountId}/repositories`, { params });
}
