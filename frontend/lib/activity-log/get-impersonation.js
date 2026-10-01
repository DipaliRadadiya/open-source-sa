import { serverFetch } from "@/lib/api/server-fetch";
import { activityResponseSchema } from "@/lib/schemas/activity";

/**
 * How often an administrator has signed in as somebody else, and when it last
 * happened. Its own request because logins dominate the feed. Counts only
 * `impersonation_started`; `meta.total` is the all-time count.
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
