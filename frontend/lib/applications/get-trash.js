import { read } from "@/lib/api/read";
import { trashResponseSchema } from "@/lib/schemas/file";

/**
 * What this site has deleted but not yet lost.
 *
 * An empty trash is a normal answer, so "we could not ask" must never render
 * as "nothing was deleted" — on this screen that reads as "your files are
 * gone". Through `read()` rather than its own try/catch, which returned one
 * `FAILED` constant for a 403, a 500, a dead request and a shape mismatch
 * alike: the panel could say the trash failed to load but never why, and
 * nothing reached the journal.
 */
export async function getTrash(appId) {
  const { data, failed, status, failure } = await read(
    `/applications/${appId}/files/trash`,
    trashResponseSchema,
  );

  return {
    trash: data?.trash ?? [],
    // The two facts the screen cannot work out for itself: how much disk this
    // is still holding, and how long any of it survives.
    totalSize: data?.total_size_human ?? null,
    retentionDays: data?.retention_days ?? null,
    failed,
    status,
    failure,
  };
}
