import { serverFetch } from "@/lib/api/server-fetch";
import { activityFiltersSchema } from "@/lib/schemas/activity";

const EMPTY = { types: [], actions: {} };

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

export function getServerActivityFilters() {
  return fetchFilters("/server/activity-log/filters");
}

export function getMyActivityFilters() {
  return fetchFilters("/activity-log/filters");
}
