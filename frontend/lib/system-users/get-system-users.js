import { serverFetch } from "@/lib/api/server-fetch";
import { read } from "@/lib/api/read";
import { systemUsersResponseSchema } from "@/lib/schemas/application";
import { listQuery, EMPTY_LIST_META } from "@/lib/schemas/list";

/**
 * Every panel-managed OS account, for pickers ("run as" on a cron job, the owner
 * on a new site).
 *
 * Requests the API maximum, since `/system-users` pages at ten. More than 100
 * accounts would need a searchable combobox, not a larger number.
 */
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

/**
 * One page of the system users list, with search applied by the API. Separate
 * from the options fetcher: a picker needs every account, a table needs one page.
 */
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
