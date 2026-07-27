import { api } from "@/lib/api/client";

// Client-side System User mutations (server panel). Each hits the configured
// Axios instance (cookie + XSRF); callers refresh the server component after.

export function createSystemUser(values) {
  return api.post("/system-users", values);
}

export function deleteSystemUser(id) {
  return api.delete(`/system-users/${id}`);
}

export function setSystemUserPassword(id, values) {
  return api.put(`/system-users/${id}/password`, values);
}

export function setSystemUserSudo(id, sudo) {
  return api.put(`/system-users/${id}/sudo`, { sudo });
}

export function setSystemUserShell(id, shell) {
  return api.put(`/system-users/${id}/shell`, { shell });
}

export function setSystemUserSsh(id, ssh_access) {
  return api.put(`/system-users/${id}/ssh`, { ssh_access });
}

// Full detail (applications with domain/status/php) — the list only returns
// minimal apps, so the Apps modal fetches this on open.
export function getSystemUserDetail(id) {
  return api.get(`/system-users/${id}`);
}

export function listSystemUserSshKeys(id) {
  return api.get(`/system-users/${id}/ssh-keys`);
}

export function addSystemUserSshKey(id, values) {
  return api.post(`/system-users/${id}/ssh-keys`, values);
}

export function deleteSystemUserSshKey(id, keyId) {
  return api.delete(`/system-users/${id}/ssh-keys/${keyId}`);
}
