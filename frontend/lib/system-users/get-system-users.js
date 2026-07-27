import { serverFetch } from "@/lib/api/server-fetch";

// GET /api/system-users — returns all panel-managed OS accounts (not paginated).
// A backend hiccup degrades to an empty list rather than crashing the page.
export async function getSystemUsers() {
  try {
    const res = await serverFetch("/system-users");
    if (!res.ok) return [];
    const data = await res.json();
    return Array.isArray(data?.system_users) ? data.system_users : [];
  } catch {
    return [];
  }
}
