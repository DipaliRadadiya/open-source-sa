import { api } from "@/lib/api/client";

export function createClone(applicationId, payload) {
  return api.post(`/applications/${applicationId}/clone`, payload);
}

export function fetchClone(cloneId) {
  return api.get(`/clones/${cloneId}`);
}
