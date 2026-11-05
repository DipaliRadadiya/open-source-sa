import { read } from "@/lib/api/read";
import { ACTIVITY_KINDS, activityResponseSchema } from "@/lib/schemas/activity";
import { perPageFrom } from "@/lib/tables/remembered-per-page";

const EMPTY_META = { current_page: 1, per_page: 10, total: 0, last_page: 1 };

// Reports failures rather than an empty list, which would read as "nothing happened".
export async function getActivityLog(searchParams = {}) {
  const perPage = await perPageFrom(searchParams.per_page);
  const page = Math.max(1, Number(searchParams.page) || 1);

  const result = await read("/admin/activity-log", activityResponseSchema, {
    searchParams: {
      search: searchParams.search?.trim() || undefined,
      "filter[type]": searchParams.type || undefined,
      "filter[action]": searchParams.action || undefined,
      "filter[kind]": ACTIVITY_KINDS.includes(searchParams.kind) ? searchParams.kind : undefined,
      "filter[security]": searchParams.security === "1" ? 1 : undefined,
      per_page: perPage,
      page,
    },
  });

  return {
    activity_log: result.data?.activity_log ?? [],
    meta: result.data?.meta ?? EMPTY_META,
    failed: result.failed,
    status: result.status,
    failure: result.failure, message: result.message, debug: result.debug,
  };
}
