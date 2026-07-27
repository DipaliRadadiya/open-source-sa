import { cache } from "react";
import { serverFetch } from "@/lib/api/server-fetch";
import { dashboardSchema } from "@/lib/schemas/dashboard";

/**
 * Admin aggregate stats (GET /admin/dashboard). Admin-only on the backend, so
 * only call this when the current user is an admin. Returns null on any
 * failure (non-admin 403, network, or shape mismatch) so the page can render
 * a graceful fallback instead of throwing.
 */
export const getDashboardStats = cache(async () => {
  const res = await serverFetch("/admin/dashboard");
  if (!res.ok) return null;

  try {
    const json = await res.json();
    const parsed = dashboardSchema.safeParse(json?.dashboard);
    return parsed.success ? parsed.data : null;
  } catch {
    return null;
  }
});
