import { api } from "@/lib/api/client";

// Everyone's rows for one type — `activity_log` permission.
export function getServerActivityByType(type, { page = 1, perPage = 20, signal } = {}) {
  return api.get("/server/activity-log", {
    params: { "filter[type]": type, page, per_page: perPage },
    signal,
  });
}

// Always the caller's own rows, with no user field.
export function getMyActivityByType(type, { page = 1, perPage = 20, signal } = {}) {
  return api.get("/activity-log", {
    params: { "filter[type]": type, page, per_page: perPage },
    signal,
  });
}
