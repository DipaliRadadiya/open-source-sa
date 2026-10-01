import { read } from "@/lib/api/read";
import { usersResponseSchema, PER_PAGE_OPTIONS } from "@/lib/schemas/user";

const EMPTY_META = { current_page: 1, per_page: 10, total: 0, last_page: 1 };

/**
 * One page of the admin user list.
 *
 * Goes through `read` so a failure reports `failed` (the page shows the
 * load-failure panel) instead of an empty list reading as "No users yet". The
 * empty meta is still returned so the pager and out-of-range redirect work.
 */
export async function getUsers(searchParams = {}) {
  const perPage = PER_PAGE_OPTIONS.includes(Number(searchParams.per_page))
    ? Number(searchParams.per_page)
    : 10;
  const page = Math.max(1, Number(searchParams.page) || 1);
  // Only the two values the API defines; anything else is dropped.
  const isAdmin =
    searchParams.is_admin === "0" || searchParams.is_admin === "1"
      ? searchParams.is_admin
      : undefined;

  const result = await read("/admin/users", usersResponseSchema, {
    searchParams: {
      search: searchParams.search?.trim() || undefined,
      "filter[is_admin]": isAdmin,
      per_page: perPage,
      page,
    },
  });

  return {
    users: result.data?.users ?? [],
    meta: result.data?.meta ?? EMPTY_META,
    failed: result.failed,
    status: result.status,
    failure: result.failure, message: result.message, debug: result.debug,
  };
}
