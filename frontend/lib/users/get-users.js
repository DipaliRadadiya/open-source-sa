import { read } from "@/lib/api/read";
import { usersResponseSchema, PER_PAGE_OPTIONS } from "@/lib/schemas/user";

const EMPTY_META = { current_page: 1, per_page: 10, total: 0, last_page: 1 };

/**
 * One page of the admin user list.
 *
 * Goes through `read` rather than handling the response here, because the
 * hand-rolled version returned an empty list on every failure and said so in
 * its own comment: "a safe empty result on any failure". It is not safe. A dead
 * API, a 500, a session that expired and a genuinely empty account all rendered
 * the same screen — "No users yet" — on the one page whose entire job is to
 * tell an administrator who can get in.
 *
 * `failed` is now carried out to the page, which shows the load-failure panel
 * instead. The empty meta is still returned alongside it so the pager and the
 * out-of-range redirect have something well-formed to read.
 */
export async function getUsers(searchParams = {}) {
  const perPage = PER_PAGE_OPTIONS.includes(Number(searchParams.per_page))
    ? Number(searchParams.per_page)
    : 10;
  const page = Math.max(1, Number(searchParams.page) || 1);
  // Only the two values the API defines; anything else is dropped rather than
  // forwarded for the backend to refuse.
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
    failure: result.failure,
  };
}
