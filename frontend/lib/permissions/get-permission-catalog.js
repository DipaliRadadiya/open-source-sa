import { serverFetch } from "@/lib/api/server-fetch";

// GET /admin/permissions — the FULL permission catalog (every permission that
// can be granted), for the role create/edit form. Distinct from
// getPermissions() (GET /permissions), which is the caller's own effective
// grants for the nav. Admin-only; items carry no view/manage state (that's
// per-role, overlaid from the role's own permissions on edit).
export async function getPermissionCatalog() {
  try {
    const res = await serverFetch("/admin/permissions");
    if (!res.ok) return [];
    const data = await res.json();
    return Array.isArray(data?.permissions) ? data.permissions : [];
  } catch {
    return [];
  }
}
