import { serverFetch } from "@/lib/api/server-fetch";
import { activityFiltersSchema } from "@/lib/schemas/activity";

const EMPTY = { types: [], actions: {} };

/**
 * Distinct type/action values for the filter dropdowns. Both scopes return the
 * same shape, so one fetcher serves both:
 *  - admin: the full catalog, populated even on a fresh install
 *  - own:   DISTINCT over the caller's own rows, so a user is never offered a
 *           filter that would match nothing — empty for a user with no history
 */
async function fetchFilters(path) {
  const res = await serverFetch(path);
  if (!res.ok) return EMPTY;

  try {
    const parsed = activityFiltersSchema.safeParse(await res.json());
    return parsed.success ? parsed.data : EMPTY;
  } catch {
    return EMPTY;
  }
}

export function getActivityFilters() {
  return fetchFilters("/admin/activity-log/filters");
}

export function getMyActivityFilters() {
  return fetchFilters("/activity-log/filters");
}
