import { serverFetch } from "@/lib/api/server-fetch";
import { rolesResponseSchema } from "@/lib/schemas/role";

/**
 * All permission roles (GET /admin/roles — returns everything, not paginated).
 * Returns [] on any failure so pages can render an empty state.
 */
export async function getRoles() {
  const res = await serverFetch("/admin/roles");
  if (!res.ok) return [];

  try {
    const json = await res.json();
    const parsed = rolesResponseSchema.safeParse(json);
    return parsed.success ? parsed.data.roles : [];
  } catch {
    return [];
  }
}
