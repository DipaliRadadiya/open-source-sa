import { api } from "@/lib/api/client";

// Client-side user mutations (admin). Each hits the configured Axios instance
// (cookie + XSRF) and returns the response; callers refresh the server
// component with router.refresh() after success.
export function createUser(values) {
  return api.post("/admin/users", values);
}

export function updateUser(id, values) {
  return api.put(`/admin/users/${id}`, values);
}

export function deleteUser(id) {
  return api.delete(`/admin/users/${id}`);
}

// Roles are synced separately from the user's core fields (many-to-many).
export function syncUserRoles(id, role_ids) {
  return api.put(`/admin/users/${id}/roles`, { role_ids });
}

export function resetUserPassword(id, values) {
  return api.put(`/admin/users/${id}/reset-password`, values);
}

// Session-based "login as" — swaps the cookie session to the target user (no
// token). Backend blocks self and admin→admin with 422. After success the
// caller must do a FULL-PAGE navigation so SSR re-reads the new session.
export function impersonateUser(id) {
  return api.post(`/admin/users/${id}/impersonate`);
}
