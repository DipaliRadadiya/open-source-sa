import { serverFetch } from "@/lib/api/server-fetch";
import { myActivityResponseSchema } from "@/lib/schemas/account";

const PER_PAGE_OPTIONS = [10, 20, 50, 100];
const EMPTY = {
  activity_log: [],
  meta: { current_page: 1, per_page: 10, total: 0, last_page: 1 },
};

/**
 * The current user's own activity history (GET /activity-log). Returns a safe
 * empty result on failure.
 */
export async function getMyActivity(searchParams = {}) {
  const perPage = PER_PAGE_OPTIONS.includes(Number(searchParams.per_page))
    ? Number(searchParams.per_page)
    : 10;
  const page = Math.max(1, Number(searchParams.page) || 1);

  const res = await serverFetch("/activity-log", {
    searchParams: { per_page: perPage, page },
  });

  if (!res.ok) return EMPTY;

  try {
    const json = await res.json();
    const parsed = myActivityResponseSchema.safeParse(json);
    return parsed.success ? parsed.data : EMPTY;
  } catch {
    return EMPTY;
  }
}
