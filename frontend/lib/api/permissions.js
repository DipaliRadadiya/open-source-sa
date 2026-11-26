import { api } from "@/lib/api/client";

export function syncPermissions() {
  return api.post("/admin/permissions/sync");
}

export async function getMyPermissions() {
  const res = await api.get("/permissions");
  return Array.isArray(res.data?.permissions) ? res.data.permissions : [];
}
