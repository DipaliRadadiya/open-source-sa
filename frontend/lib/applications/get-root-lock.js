import { read } from "@/lib/api/read";
import { rootLockResponseSchema } from "@/lib/schemas/application";

/**
 * Whether the site's folder is locked against its own user. Sites adopted by
 * server sync usually are not. A failed read returns `failed`, not "unlocked".
 */
export async function getRootLock(id) {
  const result = await read(`/applications/${id}/root-lock`, rootLockResponseSchema);
  return { rootLock: result.failed ? null : (result.data?.root_lock ?? null), failed: result.failed };
}
