import { serverFetch } from "@/lib/api/server-fetch";
import { activityFiltersSchema } from "@/lib/schemas/activity";

/**
 * Distinct type/action values for the filter dropdowns
 * (GET /admin/activity-log/filters — fully populated even on a fresh install).
 */
export async function getActivityFilters() {
  const res = await serverFetch("/admin/activity-log/filters");
  if (!res.ok) return { types: [], actions: {} };

  try {
    const json = await res.json();
    const parsed = activityFiltersSchema.safeParse(json);
    return parsed.success ? parsed.data : { types: [], actions: {} };
  } catch {
    return { types: [], actions: {} };
  }
}
