import { api } from "@/lib/api/client";
import { systemUsersResponseSchema } from "@/lib/schemas/application";

export function createSystemUser(values) {
  return api.post("/system-users", values);
}

export function deleteSystemUser(id, { endSessions = false } = {}) {
  return api.delete(`/system-users/${id}`, { params: endSessions ? { end_sessions: 1 } : undefined });
}

export function deleteSystemUsers(ids, { endSessions = false } = {}) {
  return api.delete("/system-users", { data: { ids, end_sessions: endSessions } });
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

export async function findSystemUser(username) {
  const { data } = await api.get("/system-users", { params: { search: username } });
  const parsed = systemUsersResponseSchema.safeParse(data);
  const users = parsed.success ? parsed.data.system_users : [];
  return {
    user: users.find((user) => user.username === username) ?? null,
    enforced: parsed.success ? (parsed.data.meta?.ssh_access_enforced ?? null) : null,
  };
}

export function getShellCatalog() {
  return api.get("/system-users/shells").then((res) => res.data?.shells ?? []);
}

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
