import { serverFetch } from "@/lib/api/server-fetch";
import { filesResponseSchema } from "@/lib/schemas/file";

const FAILED = { path: "", files: [], hiddenCount: 0, failed: true, notFound: false };

// `showHidden` is filtered by the API so the hidden count agrees with the rows.
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
