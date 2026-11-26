import { read } from "@/lib/api/read";
import { rootLockResponseSchema } from "@/lib/schemas/application";

export async function getRootLock(id) {
  const result = await read(`/applications/${id}/root-lock`, rootLockResponseSchema);
  return { rootLock: result.failed ? null : (result.data?.root_lock ?? null), failed: result.failed };
}
