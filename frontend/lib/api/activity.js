import { api } from "@/lib/api/client";

/**
 * The caller's own activity, filtered to one entity type.
 *
 * Client-side because it's fetched on demand from a dialog rather than with the
 * page — nobody needs the history until they ask for it.
 *
 * Note the scope: this endpoint is always the caller's own rows and carries no
 * user field. It answers "what did I change", never "who changed this". Only
 * `/admin/activity-log` spans users.
 */
export function getMyActivityByType(type, { perPage = 20, signal } = {}) {
  return api.get("/activity-log", {
    params: { "filter[type]": type, per_page: perPage },
    signal,
  });
}
