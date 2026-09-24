import { read } from "@/lib/api/read";
import { rootLockResponseSchema } from "@/lib/schemas/application";

/**
 * Whether this site's folder is locked against its own user. Every site the
 * panel creates is; a site server sync adopted usually is not, until someone
 * presses Lock. A failed read comes back as `failed`, not as "unlocked".
 */
export async function getRootLock(id) {
  const result = await read(`/applications/${id}/root-lock`, rootLockResponseSchema);
  return { rootLock: result.failed ? null : (result.data?.root_lock ?? null), failed: result.failed };
}
