import { serverFetch } from "@/lib/api/server-fetch";
import { serverFactsSchema } from "@/lib/schemas/server";

export async function getServerFacts() {
  const res = await serverFetch("/server/facts");
  if (!res.ok) return null;

  try {
    const json = await res.json();
    const parsed = serverFactsSchema.safeParse(json?.facts);
    return parsed.success ? parsed.data : null;
  } catch {
    return null;
  }
}
