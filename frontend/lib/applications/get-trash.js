import { serverFetch } from "@/lib/api/server-fetch";
import { trashResponseSchema } from "@/lib/schemas/file";

// An empty trash is a normal answer, so `failed` is the only thing worth
// distinguishing here — "we could not ask" must never render as "nothing was
// deleted", which would read as "your files are gone".
const FAILED = { trash: [], totalSize: null, retentionDays: null, failed: true };

export async function getTrash(appId) {
  try {
    const res = await serverFetch(`/applications/${appId}/files/trash`);
    if (!res.ok) return FAILED;
    const parsed = trashResponseSchema.safeParse(await res.json());
    if (!parsed.success) return FAILED;

    return {
      trash: parsed.data.trash,
      // The two facts the screen cannot work out for itself: how much disk this
      // is still holding, and how long any of it survives.
      totalSize: parsed.data.total_size_human ?? null,
      retentionDays: parsed.data.retention_days ?? null,
      failed: false,
    };
  } catch {
    return FAILED;
  }
}
