import { api } from "@/lib/api/client";

export function createRole(values) {
  return api.post("/admin/roles", values);
}

export function updateRole(id, values) {
  return api.put(`/admin/roles/${id}`, values);
}

export function deleteRole(id) {
  return api.delete(`/admin/roles/${id}`);
}
