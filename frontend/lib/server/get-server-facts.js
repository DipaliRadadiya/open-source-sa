import { cache } from "react";
import { serverFetch } from "@/lib/api/server-fetch";
import { serverFactsSchema } from "@/lib/schemas/server";

// Once per request: the layout's reboot banner and the page may both ask, and the
// endpoint runs commands on the server (~0.7 s).
export const getServerFacts = cache(async function getServerFacts() {
  const res = await serverFetch("/server/facts");
  if (!res.ok) return null;

  try {
    const json = await res.json();
    const parsed = serverFactsSchema.safeParse(json?.facts);
    return parsed.success ? parsed.data : null;
  } catch {
    return null;
  }
});
