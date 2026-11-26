import { api } from "@/lib/api/client";

const BASE = "/integrations/storage/destinations";

export function listDestinations() {
  return api.get(BASE);
}

export function createDestination(payload) {
  return api.post(BASE, payload);
}

export function updateDestination(id, payload) {
  return api.patch(`${BASE}/${id}`, payload);
}

export function testDestination(id) {
  return api.post(`${BASE}/${id}/test`);
}

export function deleteDestination(id) {
  return api.delete(`${BASE}/${id}`);
}

export function startDriveConnect(id) {
  return api.post(`${BASE}/${id}/oauth/start`);
}

export function completeDriveConnect({ code, state }) {
  return api.post("/integrations/storage/oauth/callback", { code, state });
}
