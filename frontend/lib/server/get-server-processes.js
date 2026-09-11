import { z } from "zod";
import { serverFetch } from "@/lib/api/server-fetch";
import { processSchema } from "@/lib/schemas/server";

/*
 * Returns { data, failed, total } so the UI can tell "no processes" from "fetch
 * broke" — and how many the row count is a slice OF.
 *
 * `meta.total` is the whole box; the rows are the top `meta.limit` by CPU. The
 * card had no number but its own length, so it always said 25 no matter what
 * the server was running, and stopping a process never moved it. `total` is
 * null on an API that predates the field, which the card reads as "say nothing"
 * rather than inventing a figure.
 */
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
