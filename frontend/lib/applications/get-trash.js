import { serverFetch } from "@/lib/api/server-fetch";
import { trashResponseSchema } from "@/lib/schemas/file";

// An empty trash is a normal answer, so `failed` is the only thing worth
// distinguishing here — "we could not ask" must never render as "nothing was
// deleted", which would read as "your files are gone".
const FAILED = { trash: [], failed: true };

export async function getTrash(appId) {
  try {
    const res = await serverFetch(`/applications/${appId}/files/trash`);
    if (!res.ok) return FAILED;
    const parsed = trashResponseSchema.safeParse(await res.json());
    return parsed.success ? { trash: parsed.data.trash, failed: false } : FAILED;
  } catch {
    return FAILED;
  }
}
