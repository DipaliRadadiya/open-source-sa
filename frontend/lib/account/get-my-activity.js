import { read } from "@/lib/api/read";
import { myActivityResponseSchema } from "@/lib/schemas/account";

const PER_PAGE_OPTIONS = [10, 20, 50, 100];
const EMPTY = {
  activity_log: [],
  meta: { current_page: 1, per_page: 10, total: 0, last_page: 1 },
};

// Carries which failure (refusal, crash, unreachable) so the page can name it.
const failedWith = (result) => ({
  ...EMPTY,
  failed: true,
  status: result.status,
  failure: result.failure,
  message: result.message,
  debug: result.debug,
});

// Returns a safe empty result on failure.
export async function getMyActivity(searchParams = {}, scope) {
  const perPage = PER_PAGE_OPTIONS.includes(Number(searchParams.per_page))
    ? Number(searchParams.per_page)
    : 10;
  const page = Math.max(1, Number(searchParams.page) || 1);

  // No filter[user_id]: the endpoint is always scoped to the caller, and
  // `search` matches type + action only.
  const result = await read("/activity-log", myActivityResponseSchema, {
    searchParams: {
      search: searchParams.search?.trim() || undefined,
      // Fixed by the page, not the URL, so the query string cannot widen it.
      "filter[scope]": scope || undefined,
      "filter[type]": searchParams.type || undefined,
      "filter[action]": searchParams.action || undefined,
      per_page: perPage,
      page,
    },
  });

  // A failure is flagged, not returned as an empty list (which would read as
  // "no activity" or "no matches"), and not thrown: the rest of the page works.
  if (result.failed) return failedWith(result);

  return { ...result.data, failed: false, status: result.status, failure: null, message: null, debug: false };
}
