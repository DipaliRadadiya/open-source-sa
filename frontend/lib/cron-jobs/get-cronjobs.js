import { read } from "@/lib/api/read";
import { cronjobsResponseSchema } from "@/lib/schemas/cronjob";

const PER_PAGE_OPTIONS = [10, 20, 50, 100];
// Exactly what the Status dropdown writes. Anything else is not a filter.
const ACTIVE_VALUES = ["true", "false"];
// `failed` separates "you have no cron jobs" from "we couldn't ask" — rendered
// the same, the empty state would tell the user their jobs are gone.
const EMPTY = {
  cronjobs: [],
  meta: { current_page: 1, per_page: 10, total: 0, last_page: 1 },
};

// WHICH failure, not just that there was one. A shared `FAILED` constant made
// every outcome identical, so the error box could not tell a refusal from a
// crash and printed the same unfalsifiable sentence for both.
const failedWith = (result) => ({
  ...EMPTY,
  failed: true,
  status: result.status,
  failure: result.failure,
  message: result.message,
  debug: result.debug,
});

/**
 * GET /api/cronjobs — paginated. Maps the URL's `user`/`active` params onto the
 * backend's `filter[...]` shape. `user` holds either a system-user id (numeric)
 * or a bare OS username, which are different filters server-side.
 */
export async function getCronjobs(searchParams = {}) {
  const perPage = PER_PAGE_OPTIONS.includes(Number(searchParams.per_page))
    ? Number(searchParams.per_page)
    : 10;
  const page = Math.max(1, Number(searchParams.page) || 1);

  const user = searchParams.user?.trim();
  const isSystemUser = user && /^\d+$/.test(user);

  // Only a value the toolbar can offer back. The URL is editable and shared,
  // so `?active=bogus` is reachable — and PHP read it as false, quietly
  // filtering the list to paused jobs. Forwarded, the control and the list
  // disagree whatever the dropdown then shows: either it renders blank, or it
  // says "All statuses" over a list that is filtered. Dropping it here makes
  // both true.
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
