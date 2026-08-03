import { serverFetch } from "@/lib/api/server-fetch";

/**
 * The server's total RAM, used to recommend a swap size.
 *
 * Lives behind the `dashboard` permission, so it is optional context: without
 * it the swap card still works, it just can't say which size suits this machine
 * (same pattern as the SSH port on the firewall page).
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
