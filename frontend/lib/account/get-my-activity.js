import { read } from "@/lib/api/read";
import { myActivityResponseSchema } from "@/lib/schemas/account";
import { perPageFrom } from "@/lib/tables/remembered-per-page";

const EMPTY = {
  activity_log: [],
  meta: { current_page: 1, per_page: 10, total: 0, last_page: 1 },
};

const failedWith = (result) => ({
  ...EMPTY,
  failed: true,
  status: result.status,
  failure: result.failure,
  message: result.message,
  debug: result.debug,
});

export async function getMyActivity(searchParams = {}, scope) {
  const perPage = await perPageFrom(searchParams.per_page);
  const page = Math.max(1, Number(searchParams.page) || 1);

  const result = await read("/activity-log", myActivityResponseSchema, {
    searchParams: {
      search: searchParams.search?.trim() || undefined,
      // Set by the page, not the URL, so the query string can't widen it.
      "filter[scope]": scope || undefined,
      "filter[type]": searchParams.type || undefined,
      "filter[action]": searchParams.action || undefined,
      per_page: perPage,
      page,
    },
  });

  if (result.failed) return failedWith(result);

  return { ...result.data, failed: false, status: result.status, failure: null, message: null, debug: false };
}
