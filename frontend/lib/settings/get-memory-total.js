import { serverFetch } from "@/lib/api/server-fetch";

// Optional (`dashboard` permission). Resolves `{ bytes, human }` or null.
export async function getMemoryTotal() {
  try {
    const res = await serverFetch("/server/facts");
    if (!res.ok) return null;

    const body = await res.json();
    const bytes = body?.facts?.memory_total;
    if (typeof bytes !== "number" || bytes <= 0) return null;

    return { bytes, human: body?.facts?.memory_total_human ?? null };
  } catch {
    return null;
  }
}
