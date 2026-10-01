import { serverFetch } from "@/lib/api/server-fetch";
import { breakdownResponseSchema } from "@/lib/schemas/file";

/**
 * What is using this folder's space, by kind of file. Never blocks the page:
 * large folders can time out. A failure returns `null` ("could not measure"),
 * distinct from an empty folder.
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
