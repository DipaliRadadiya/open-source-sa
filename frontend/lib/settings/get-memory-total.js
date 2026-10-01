import { serverFetch } from "@/lib/api/server-fetch";

/**
 * The server's total RAM, used to recommend a swap size. Behind the
 * `dashboard` permission, so optional: the swap card works without it.
 *
 * @returns {Promise<{bytes: number, human: string} | null>}
 */
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
