import { z } from "zod";
import { serverFetch } from "@/lib/api/server-fetch";
import { accessLevelSchema, permissionGroupSchema } from "@/lib/schemas/role";

// `groups` buckets by level AND sub-level, so same-named keys at different levels stay
// separate. Distinct from getPermissions(), the caller's own effective grants.
export async function getPermissionCatalog() {
  // The role form MUST NOT open on an empty catalog: it saves every grant it
  // shows, so saving an empty list would strip the role's permissions.
  const empty = (status = null, failure = status ? "http" : "network") =>
    ({ permissions: [], groups: [], accessLevels: [], failed: true, status, failure });
  try {
    const res = await serverFetch("/admin/permissions");
    if (!res.ok) return empty(res.status);
    const data = await res.json();

    const permissions = Array.isArray(data?.permissions) ? data.permissions : [];
    if (!permissions.length) return empty(res.status, "shape");
    const groups = z.array(permissionGroupSchema).safeParse(data?.groups);
    const accessLevels = z.array(accessLevelSchema).safeParse(data?.access_levels);

    return {
      permissions,
      // Without groups (older backend), fall back to one section per level.
      groups: groups.success && groups.data.length
        ? groups.data
        : groupByLevel(permissions),
      accessLevels: accessLevels.success ? accessLevels.data : [],
      failed: false,
    };
  } catch {
    return empty();
  }
}

function groupByLevel(permissions) {
  const buckets = new Map();
  for (const permission of permissions) {
    const level = permission.level ?? "";
    if (!buckets.has(level)) {
      buckets.set(level, {
        level,
        sub_level: "",
        sub_level_title: null,
        permissions: [],
      });
    }
    buckets.get(level).permissions.push(permission);
  }
  return [...buckets.values()];
}
