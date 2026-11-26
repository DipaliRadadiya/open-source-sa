import { read } from "@/lib/api/read";
import { syncHandoverSchema, syncRunResponseSchema, syncIgnoresResponseSchema } from "@/lib/schemas/sync";

export function getLatestSyncRun() {
  return read("/server/sync/latest", syncRunResponseSchema);
}

export function getSyncRunItems(runId, since = 0) {
  return read(`/server/sync/${runId}`, syncRunResponseSchema, {
    searchParams: { since },
  });
}

export function getSyncIgnores() {
  return read("/server/sync/ignores", syncIgnoresResponseSchema);
}

export function getSyncHandover() {
  return read("/server/sync/handover", syncHandoverSchema);
}
