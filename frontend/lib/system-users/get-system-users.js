import { serverFetch } from "@/lib/api/server-fetch";
import { read } from "@/lib/api/read";
import { systemUsersResponseSchema } from "@/lib/schemas/application";
import { listQuery, EMPTY_LIST_META } from "@/lib/schemas/list";

/**
 * Every panel-managed OS account, for the pickers — "run as" on a cron job,
 * the owner on a new site.
 *
 * `/system-users` pages at ten now, so this asks for the API's maximum rather
 * than taking the default and quietly offering the first ten accounts as if
 * they were all of them. A server with more than 100 would need a searchable
 * combobox here, not a larger number.
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
 * One page of the system users list, for the screen that manages them.
 *
 * Separate from the options fetcher above because they want opposite things: a
 * picker needs every account, a table needs the page you asked for and the
 * search applied by the API rather than to whichever ten rows arrived.
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
    failure: result.failure,
  };
}
