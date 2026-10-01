import { serverFetch } from "@/lib/api/server-fetch";
import { read } from "@/lib/api/read";
import { systemUsersResponseSchema } from "@/lib/schemas/application";
import { listQuery, EMPTY_LIST_META } from "@/lib/schemas/list";

// Requests the API maximum, since `/system-users` pages at ten. Past 100 accounts the
// picker needs search, not a larger number.
export async function getSystemUserOptions() {
  try {
    const res = await serverFetch("/system-users", { searchParams: { per_page: 100 } });
    if (!res.ok) return { users: [], failed: true };
    const parsed = systemUsersResponseSchema.safeParse(await res.json());
    return parsed.success
      ? { users: parsed.data.system_users, failed: false }
      : { users: [], failed: true };
  } catch {
    return { users: [], failed: true };
  }
}

export async function getSystemUsers() {
  const result = await getSystemUserOptions();
  return result.users;
}

/** A picker needs every account; a table needs one page. */
export async function getSystemUsersPage(query = "") {
  const result = await read("/system-users", systemUsersResponseSchema, {
    searchParams: listQuery(query),
  });
  return {
    users: result.data?.system_users ?? [],
    meta: result.data?.meta ?? EMPTY_LIST_META,
    failed: result.failed,
    status: result.status,
    failure: result.failure, message: result.message, debug: result.debug,
  };
}
