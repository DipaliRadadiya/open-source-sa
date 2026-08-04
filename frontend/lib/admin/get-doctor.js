import { cache } from "react";
import { serverFetch } from "@/lib/api/server-fetch";
import { doctorSchema } from "@/lib/schemas/doctor";

/**
 * Installation self-check (GET /admin/doctor). Admin-only on the backend, so
 * only call it for an admin. Returns null on any failure (403, network, shape
 * mismatch) so the page renders a graceful fallback rather than throwing.
 * Not cached across the request in a way that survives re-runs — a "Re-check"
 * is a full router.refresh(), which re-invokes this.
 */
export const getDoctor = cache(async () => {
  try {
    const res = await serverFetch("/admin/doctor");
    if (!res.ok) return null;
    const json = await res.json();
    const parsed = doctorSchema.safeParse(json?.doctor);
    return parsed.success ? parsed.data : null;
  } catch {
    return null;
  }
});
