import { z } from "zod";
import { serverFetch } from "@/lib/api/server-fetch";
import { processSchema } from "@/lib/schemas/server";

// Returns { data, failed } so the UI can tell "no processes" from "fetch broke".
export async function getServerProcesses() {
  const res = await serverFetch("/server/processes");
  if (!res.ok) return { data: [], failed: true };

  try {
    const json = await res.json();
    const parsed = z.array(processSchema).safeParse(json?.processes);
    return parsed.success
      ? { data: parsed.data, failed: false }
      : { data: [], failed: true };
  } catch {
    return { data: [], failed: true };
  }
}
