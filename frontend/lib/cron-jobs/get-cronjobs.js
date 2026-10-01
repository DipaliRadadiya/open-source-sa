import { read } from "@/lib/api/read";
import { cronjobsResponseSchema } from "@/lib/schemas/cronjob";

const PER_PAGE_OPTIONS = [10, 20, 50, 100];
// Exactly what the Status dropdown writes; anything else is not a filter.
const ACTIVE_VALUES = ["true", "false"];
// `failed` separates "no cron jobs" from "couldn't ask"; otherwise the empty state
// would claim the jobs are gone.
const EMPTY = {
  cronjobs: [],
  meta: { current_page: 1, per_page: 10, total: 0, last_page: 1 },
};

// Keeps status and kind so the error box can tell a refusal from a crash.
const failedWith = (result) => ({
  ...EMPTY,
  failed: true,
  status: result.status,
  failure: result.failure,
  message: result.message,
  debug: result.debug,
});

// `user` is a system-user id (numeric) or a bare OS username: different filters server-side.
export async function getCronjobs(searchParams = {}) {
  const perPage = PER_PAGE_OPTIONS.includes(Number(searchParams.per_page))
    ? Number(searchParams.per_page)
    : 10;
  const page = Math.max(1, Number(searchParams.page) || 1);

  const user = searchParams.user?.trim();
  const isSystemUser = user && /^\d+$/.test(user);

  // Only values the toolbar can show. The backend reads `?active=bogus` as false,
  // which would filter to paused jobs while the dropdown shows "All statuses".
  const active = ACTIVE_VALUES.includes(searchParams.active)
    ? searchParams.active
    : undefined;

  const result = await read("/cronjobs", cronjobsResponseSchema, {
    searchParams: {
      "filter[system_user_id]": isSystemUser ? user : undefined,
      "filter[username]": user && !isSystemUser ? user : undefined,
      "filter[active]": active,
      per_page: perPage,
      page,
    },
  });

  if (result.failed) return failedWith(result);

  return { ...result.data, failed: false, status: result.status, failure: null, message: null, debug: false };
}
