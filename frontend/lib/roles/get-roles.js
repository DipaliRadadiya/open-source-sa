import { serverFetch } from "@/lib/api/server-fetch";
import { read } from "@/lib/api/read";
import { rolesResponseSchema } from "@/lib/schemas/role";
import { listQuery, EMPTY_LIST_META } from "@/lib/schemas/list";

/**
 * Every permission role, for the role checkboxes on a user.
 *
 * `/admin/roles` is paginated, so this asks for the API's maximum; otherwise
 * only the first page would be offered. Reports `failed` rather than an empty
 * list, so callers do not claim "no roles exist" or 404 a real role.
 */
export async function getRoles() {
  const failure = { roles: [], failed: true };

  try {
    const res = await serverFetch("/admin/roles", { searchParams: { per_page: 100 } });
    if (!res.ok) return failure;

    const parsed = rolesResponseSchema.safeParse(await res.json());
    return parsed.success ? { roles: parsed.data.roles, failed: false } : failure;
  } catch {
    return failure;
  }
}

/**
 * One page of the roles list, with search and paging done by the API.
 */
export async function getRolesPage(query = "") {
  const result = await read("/admin/roles", rolesResponseSchema, {
    searchParams: listQuery(query),
  });
  return {
    roles: result.data?.roles ?? [],
    meta: result.data?.meta ?? EMPTY_LIST_META,
    failed: result.failed,
    status: result.status,
    failure: result.failure, message: result.message, debug: result.debug,
  };
}
