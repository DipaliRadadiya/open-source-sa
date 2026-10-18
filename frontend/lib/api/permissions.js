import { api } from "@/lib/api/client";

// POST /admin/permissions/sync — reseed the permission catalog from code and
// re-sync the Administrator role. Returns { permissions, synced }.
export function syncPermissions() {
  return api.post("/admin/permissions/sync");
}

// The caller's own catalog (GET /permissions), for the client to pick a landing page.
export async function getMyPermissions() {
  const res = await api.get("/permissions");
  return Array.isArray(res.data?.permissions) ? res.data.permissions : [];
}
