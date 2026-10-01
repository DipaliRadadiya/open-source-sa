import { cache } from "react";
import { serverFetch } from "@/lib/api/server-fetch";
import { dashboardSchema } from "@/lib/schemas/dashboard";

// Admin-only on the backend. Returns null on any failure so the page can render a fallback.
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
