import { read } from "@/lib/api/read";
import { ACTIVITY_KINDS, activityResponseSchema } from "@/lib/schemas/activity";
import { perPageFrom } from "@/lib/tables/remembered-per-page";

const EMPTY_META = { current_page: 1, per_page: 10, total: 0, last_page: 1 };

/** Account events (logins, roles, users) are not in it; those stay personal. */
export async function getServerActivity(searchParams = {}) {
  const perPage = await perPageFrom(searchParams.per_page);
  const page = Math.max(1, Number(searchParams.page) || 1);

  const result = await read("/server/activity-log", activityResponseSchema, {
    searchParams: {
      search: searchParams.search?.trim() || undefined,
      "filter[type]": searchParams.type || undefined,
      "filter[action]": searchParams.action || undefined,
      "filter[kind]": ACTIVITY_KINDS.includes(searchParams.kind) ? searchParams.kind : undefined,
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
    message: result.message,
  };
}
