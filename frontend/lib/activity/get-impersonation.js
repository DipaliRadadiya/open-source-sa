import { serverFetch } from "@/lib/api/server-fetch";
import { activityResponseSchema } from "@/lib/schemas/activity";

/**
 * How often an administrator has signed in as somebody else, and when it last
 * happened.
 *
 * Its own request rather than a slice of the dashboard feed, because the feed
 * is dominated by logins — the last hundred entries on a live panel were 99
 * `logged_in` and one sync, so an impersonation from last week is nowhere in
 * the window. Asking the API for exactly this action is one call and cannot be
 * diluted.
 *
 * Only `impersonation_started` is counted: every session has a start, and
 * stops are the same event seen from the other end.
 *
 * `meta.total` is the all-time count for that filter, which is the honest
 * number here — "twice, ever" and "twice this week" are different facts and the
 * endpoint can only answer the first.
 */
const EMPTY = { total: 0, last: null, failed: false };

export async function getImpersonation() {
  const res = await serverFetch("/admin/activity-log", {
    searchParams: { "filter[action]": "impersonation_started", per_page: 10 },
  });

  if (!res.ok) return { ...EMPTY, failed: true };

  try {
    const parsed = activityResponseSchema.safeParse(await res.json());
    if (!parsed.success) return { ...EMPTY, failed: true };
    return {
      total: parsed.data.meta.total,
      last: parsed.data.activity_log[0] ?? null,
      failed: false,
    };
  } catch {
    return { ...EMPTY, failed: true };
  }
}
