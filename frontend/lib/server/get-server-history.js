import { z } from "zod";
import { serverFetch } from "@/lib/api/server-fetch";
import { historyPointSchema } from "@/lib/schemas/server";

export async function getServerHistory() {
  const res = await serverFetch("/server/metrics/history");
  if (!res.ok) return [];

  try {
    const json = await res.json();
    const parsed = z.array(historyPointSchema).safeParse(json?.metrics);
    return parsed.success ? parsed.data : [];
  } catch {
    return [];
  }
}
