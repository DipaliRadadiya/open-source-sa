import { read } from "@/lib/api/read";
import { syncRunResponseSchema, syncIgnoresResponseSchema } from "@/lib/schemas/sync";

/**
 * The most recent run, so a screen reopened after a refresh shows the last
 * result instead of an empty page that implies nothing was ever scanned.
 *
 * `sync` is legitimately null on a server that has never run one, and that is
 * the feature's normal first state — it must not be rendered as a load failure.
 * The items relation is NOT loaded by this endpoint; the panel fetches them
 * from GET /server/sync/{run} once it knows the id.
 */
export function getLatestSyncRun() {
  return read("/server/sync/latest", syncRunResponseSchema);
}

/**
 * One run's items, from a cursor. At most 500 come back per call.
 *
 * Server-side this only ever fetches the first page: draining the rest is the
 * client's job, since it is already polling for new rows and a server render
 * that looped until the feed ran dry would hold the page open for as long as
 * the box has vhosts.
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
