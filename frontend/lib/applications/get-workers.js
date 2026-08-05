import { serverFetch } from "@/lib/api/server-fetch";
import { workersResponseSchema } from "@/lib/schemas/worker";

const FAILED = { workers: [], presets: [], checks: [], failed: true };

export async function getWorkers(appId) {
  try {
    const res = await serverFetch(`/applications/${appId}/workers`);
    if (!res.ok) return FAILED;
    const parsed = workersResponseSchema.safeParse(await res.json());
    return parsed.success ? { ...parsed.data, failed: false } : FAILED;
  } catch {
    return FAILED;
  }
}
