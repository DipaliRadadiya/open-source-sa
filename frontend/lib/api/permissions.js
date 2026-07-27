import { api } from "@/lib/api/client";

// POST /admin/permissions/sync — reseed the permission catalog from code and
// re-sync the Administrator role. Returns { permissions, synced }.
export function syncPermissions() {
  return api.post("/admin/permissions/sync");
}
