import { read } from "@/lib/api/read";
import { syncRunResponseSchema, syncIgnoresResponseSchema } from "@/lib/schemas/sync";

/**
 * The most recent sync run. `sync` is null when none has run yet, which is not
 * a load failure. Items are not included; fetch them from GET /server/sync/{run}.
 */
export function getLatestSyncRun() {
  return read("/server/sync/latest", syncRunResponseSchema);
}

/**
 * One run's items from a cursor, at most 500 per call. Server-side fetches only
 * the first page; the client drains the rest while polling.
 */
export function getSyncRunItems(runId, since = 0) {
  return read(`/server/sync/${runId}`, syncRunResponseSchema, {
    searchParams: { since },
  });
}

/** Everything previously dismissed, and the ids needed to undo it. */
export function getSyncIgnores() {
  return read("/server/sync/ignores", syncIgnoresResponseSchema);
}
