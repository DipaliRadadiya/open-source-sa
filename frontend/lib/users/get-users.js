import { serverFetch } from "@/lib/api/server-fetch";
import { usersResponseSchema, PER_PAGE_OPTIONS } from "@/lib/schemas/user";

const EMPTY = {
  users: [],
  meta: { current_page: 1, per_page: 10, total: 0, last_page: 1 },
};

/**
 * Normalize raw searchParams into the backend's query shape and fetch the
 * paginated user list. `is_admin` ("1"/"0") maps to Laravel's
 * `filter[is_admin]`. Returns a safe empty result on any failure.
 */
export async function getUsers(searchParams = {}) {
  const perPage = PER_PAGE_OPTIONS.includes(Number(searchParams.per_page))
    ? Number(searchParams.per_page)
    : 10;
  const page = Math.max(1, Number(searchParams.page) || 1);
  const isAdmin =
    searchParams.is_admin === "0" || searchParams.is_admin === "1"
      ? searchParams.is_admin
      : undefined;
  const search = searchParams.search?.trim() || undefined;

  const res = await serverFetch("/admin/users", {
    searchParams: {
      search,
      "filter[is_admin]": isAdmin,
      per_page: perPage,
      page,
    },
  });

  if (!res.ok) return EMPTY;

  try {
    const json = await res.json();
    const parsed = usersResponseSchema.safeParse(json);
    return parsed.success ? parsed.data : EMPTY;
  } catch {
    return EMPTY;
  }
}
