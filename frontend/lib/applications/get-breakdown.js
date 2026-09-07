import { serverFetch } from "@/lib/api/server-fetch";
import { breakdownResponseSchema } from "@/lib/schemas/file";

/**
 * What is using this folder's space, by kind of file.
 *
 * Never blocks the page: a directory too large to walk inside the backend's
 * timeout is an ordinary outcome here, and the file manager is still worth
 * showing without its chart. A failure returns `null` so the card can say it
 * could not measure, which is a different sentence from "this folder is empty".
 */
export async function getBreakdown(appId, path = "") {
  try {
    const res = await serverFetch(`/applications/${appId}/files/breakdown`, {
      searchParams: { path },
    });
    if (!res.ok) return null;

    const parsed = breakdownResponseSchema.safeParse(await res.json());

    return parsed.success ? parsed.data.breakdown : null;
  } catch {
    return null;
  }
}
