import { serverFetch } from "@/lib/api/server-fetch";
import { activityResponseSchema } from "@/lib/schemas/activity";

const PER_PAGE_OPTIONS = [10, 20, 50, 100];
const EMPTY = {
  activity_log: [],
  meta: { current_page: 1, per_page: 10, total: 0, last_page: 1 },
};

/**
 * Admin-wide activity log (GET /admin/activity-log). Maps `type`/`action` to
 * the backend's `filter[...]` params. Returns a safe empty result on failure.
 */
export async function getActivityLog(searchParams = {}) {
  const perPage = PER_PAGE_OPTIONS.includes(Number(searchParams.per_page))
    ? Number(searchParams.per_page)
    : 10;
  const page = Math.max(1, Number(searchParams.page) || 1);

  const res = await serverFetch("/admin/activity-log", {
    searchParams: {
      search: searchParams.search?.trim() || undefined,
      "filter[type]": searchParams.type || undefined,
      "filter[action]": searchParams.action || undefined,
      per_page: perPage,
      page,
    },
  });

  if (!res.ok) return EMPTY;

  try {
    const json = await res.json();
    const parsed = activityResponseSchema.safeParse(json);
    return parsed.success ? parsed.data : EMPTY;
  } catch {
    return EMPTY;
  }
}
