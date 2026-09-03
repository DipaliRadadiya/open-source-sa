import { read } from "@/lib/api/read";
import { activityResponseSchema } from "@/lib/schemas/activity";

const PER_PAGE_OPTIONS = [10, 20, 50, 100];
const EMPTY_META = { current_page: 1, per_page: 10, total: 0, last_page: 1 };

/**
 * One page of the server-wide activity log.
 *
 * Same correction as getUsers: this returned an empty list on every failure, so
 * an unreachable API rendered as "nothing has happened here". On an audit log
 * that reading is worse than useless — the screen exists to answer "what was
 * done to this server", and silence is the one answer it must never invent.
 */
export async function getActivityLog(searchParams = {}) {
  const perPage = PER_PAGE_OPTIONS.includes(Number(searchParams.per_page))
    ? Number(searchParams.per_page)
    : 10;
  const page = Math.max(1, Number(searchParams.page) || 1);

  const result = await read("/admin/activity-log", activityResponseSchema, {
    searchParams: {
      search: searchParams.search?.trim() || undefined,
      "filter[type]": searchParams.type || undefined,
      "filter[action]": searchParams.action || undefined,
      per_page: perPage,
      page,
    },
  });

  return {
    activity_log: result.data?.activity_log ?? [],
    meta: result.data?.meta ?? EMPTY_META,
    failed: result.failed,
    status: result.status,
    failure: result.failure,
  };
}
