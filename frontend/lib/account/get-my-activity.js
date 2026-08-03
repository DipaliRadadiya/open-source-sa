import { serverFetch } from "@/lib/api/server-fetch";
import { myActivityResponseSchema } from "@/lib/schemas/account";

const PER_PAGE_OPTIONS = [10, 20, 50, 100];
const FAILED = {
  activity_log: [],
  meta: { current_page: 1, per_page: 10, total: 0, last_page: 1 },
  failed: true,
};

/**
 * The current user's own activity history (GET /activity-log). Returns a safe
 * empty result on failure.
 */
export async function getMyActivity(searchParams = {}, scope) {
  const perPage = PER_PAGE_OPTIONS.includes(Number(searchParams.per_page))
    ? Number(searchParams.per_page)
    : 10;
  const page = Math.max(1, Number(searchParams.page) || 1);

  // No filter[user_id] here — the endpoint is always scoped to the caller, and
  // `search` matches type + action only (there's one actor, so no names).
  const res = await serverFetch("/activity-log", {
    searchParams: {
      search: searchParams.search?.trim() || undefined,
      // Fixed by the page, not the URL: the server page is server rows and the
      // account tab is account rows. Nothing in the query string can widen it.
      "filter[scope]": scope || undefined,
      "filter[type]": searchParams.type || undefined,
      "filter[action]": searchParams.action || undefined,
      per_page: perPage,
      page,
    },
  });

  // A failed request must not degrade to an empty list: "nothing happened yet"
  // and "we couldn't load your history" look identical to the user, and with
  // filters applied it reads as "no matches" — a wrong answer, not an error.
  // It's flagged rather than thrown: the rest of the account page is fine.
  if (!res.ok) return FAILED;

  const parsed = myActivityResponseSchema.safeParse(await res.json());
  return parsed.success ? { ...parsed.data, failed: false } : FAILED;
}
