import { serverFetch } from "@/lib/api/server-fetch";
import { read } from "@/lib/api/read";
import { rolesResponseSchema } from "@/lib/schemas/role";
import { listQuery, EMPTY_LIST_META } from "@/lib/schemas/list";

/**
 * Every permission role, for the pickers — the role checkboxes on a user.
 *
 * `/admin/roles` pages at ten now, so this asks for the API's maximum. Taking
 * the default would offer the first ten roles as though they were all of them,
 * and a user saved without a role they were never shown is a permission bug
 * that looks like a UI one.
 *
 * Reports `failed` rather than answering an empty list, because callers cannot
 * tell those apart and both of them said the wrong thing:
 *
 * - The user form told an administrator "No roles exist yet. Create a role
 *   first" — sending them to create something that already exists.
 * - The role editor matches an id against this list, so a failure meant
 *   `notFound()`: a 404 stating that a role which does exist does not.
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
 * One page of the roles list, for the screen that manages them — search and
 * paging answered by the API rather than applied to the page we hold.
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
    failure: result.failure,
  };
}
