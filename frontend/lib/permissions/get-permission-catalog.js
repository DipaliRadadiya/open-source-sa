import { z } from "zod";
import { serverFetch } from "@/lib/api/server-fetch";
import { accessLevelSchema, permissionGroupSchema } from "@/lib/schemas/role";

/**
 * GET /admin/permissions — everything the role form needs, in the three shapes
 * the server already has it in.
 *
 * `permissions` is the flat ordered list. `groups` is the same rows bucketed by
 * level AND sub-level, with section titles already localised — grouping it here
 * rather than client-side is what keeps `logs` at server level and `logs` at
 * application level as two sections instead of one control over both.
 * `accessLevels` names the three states a grant can hold, in the order they
 * should be offered.
 *
 * Distinct from getPermissions() (GET /permissions), which is the caller's own
 * effective grants for the nav. Admin-only.
 */
export async function getPermissionCatalog() {
  // `failed`: the role form must not open on an empty catalog. It saves every
  // grant it shows, so an empty list saved as "no permissions" and stripped
  // the role — and everyone holding it — under a "Role updated." toast.
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
      // No groups (an older backend) falls back to one section per level, so
      // the form still renders rather than showing nothing.
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
