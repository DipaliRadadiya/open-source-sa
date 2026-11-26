import { api } from "@/lib/api/client";

export function getServerActivityByType(type, { page = 1, perPage = 20, signal } = {}) {
  return api.get("/server/activity-log", {
    params: { "filter[type]": type, page, per_page: perPage },
    signal,
  });
}

export function getMyActivityByType(type, { page = 1, perPage = 20, signal } = {}) {
  return api.get("/activity-log", {
    params: { "filter[type]": type, page, per_page: perPage },
    signal,
  });
}

export function updateActivityRetention(months) {
  return api.put("/admin/activity-log/retention", { months });
}
