import { serverFetch } from "@/lib/api/server-fetch";
import { filesResponseSchema } from "@/lib/schemas/file";

const FAILED = { path: "", files: [], hiddenCount: 0, failed: true, notFound: false };

/**
 * One directory listing.
 *
 * `showHidden` is sent to the API rather than applied here. The backend filters
 * from the same `find` that produced the list, so the count of what was held
 * back cannot disagree with the rows — which it could if the browser filtered a
 * listing the server had already moved on from.
 */
export async function getFiles(appId, path = "", showHidden = true) {
  try {
    const res = await serverFetch(`/applications/${appId}/files`, {
      searchParams: { path, hidden: showHidden ? "1" : "0" },
    });
    if (res.status === 404) return { ...FAILED, notFound: true };
    if (!res.ok) return FAILED;
    const parsed = filesResponseSchema.safeParse(await res.json());
    return parsed.success
      ? {
          ...parsed.data,
          hiddenCount: parsed.data.hidden_count ?? 0,
          failed: false,
          notFound: false,
        }
      : FAILED;
  } catch {
    return FAILED;
  }
}
