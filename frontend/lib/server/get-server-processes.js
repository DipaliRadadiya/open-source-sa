import { z } from "zod";
import { serverFetch } from "@/lib/api/server-fetch";
import { processSchema } from "@/lib/schemas/server";

// Rows are the top `meta.limit` by CPU; `total` is null on older APIs, and the card
// then shows no count.
export async function getServerProcesses() {
  const res = await serverFetch("/server/processes");
  if (!res.ok) return { data: [], failed: true, total: null };

  try {
    const json = await res.json();
    const parsed = z.array(processSchema).safeParse(json?.processes);
    const total = Number(json?.meta?.total);
    return parsed.success
      ? { data: parsed.data, failed: false, total: Number.isFinite(total) ? total : null }
      : { data: [], failed: true, total: null };
  } catch {
    return { data: [], failed: true, total: null };
  }
}
