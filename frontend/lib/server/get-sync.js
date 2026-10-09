import { read } from "@/lib/api/read";
import { syncHandoverSchema, syncRunResponseSchema, syncIgnoresResponseSchema } from "@/lib/schemas/sync";

// `sync` is null when none has run yet, which is not a failure. Items are not included.
export function getLatestSyncRun() {
  return read("/server/sync/latest", syncRunResponseSchema);
}

// At most 500 per call; the server fetches the first page and the client drains the rest.
export function getSyncRunItems(runId, since = 0) {
  return read(`/server/sync/${runId}`, syncRunResponseSchema, {
    searchParams: { since },
  });
}

/** Everything previously dismissed, and the ids needed to undo it. */
export function getSyncIgnores() {
  return read("/server/sync/ignores", syncIgnoresResponseSchema);
}

// Read-only; a failure hides the card rather than claiming nothing is left.
export function getSyncHandover() {
  return read("/server/sync/handover", syncHandoverSchema);
}
