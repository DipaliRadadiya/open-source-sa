import { api } from "@/lib/api/client";

export function createUser(values) {
  return api.post("/admin/users", values);
}

export function updateUser(id, values) {
  return api.put(`/admin/users/${id}`, values);
}

export function deleteUser(id) {
  return api.delete(`/admin/users/${id}`);
}

export function syncUserRoles(id, role_ids) {
  return api.put(`/admin/users/${id}/roles`, { role_ids });
}

export function resetUserPassword(id, values) {
  return api.put(`/admin/users/${id}/reset-password`, values);
}

// Caller must do a full page load afterwards so SSR reads the new session.
export function impersonateUser(id) {
  return api.post(`/admin/users/${id}/impersonate`);
}
