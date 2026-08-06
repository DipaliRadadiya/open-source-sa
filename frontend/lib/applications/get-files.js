import { serverFetch } from "@/lib/api/server-fetch";
import { filesResponseSchema } from "@/lib/schemas/file";

const FAILED = { path: "", files: [], failed: true, notFound: false };

export async function getFiles(appId, path = "") {
  try {
    const res = await serverFetch(`/applications/${appId}/files`, {
      searchParams: { path },
    });
    if (res.status === 404) return { ...FAILED, notFound: true };
    if (!res.ok) return FAILED;
    const parsed = filesResponseSchema.safeParse(await res.json());
    return parsed.success ? { ...parsed.data, failed: false, notFound: false } : FAILED;
  } catch {
    return FAILED;
  }
}
