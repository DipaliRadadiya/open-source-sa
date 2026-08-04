import { serverFetch } from "@/lib/api/server-fetch";
import { systemUsersResponseSchema } from "@/lib/schemas/application";

// GET /api/system-users — returns all panel-managed OS accounts (not paginated).
// Callers that need to explain a failed picker can use the richer result;
// existing list pages keep their simple array contract.
export async function getSystemUserOptions() {
  try {
    const res = await serverFetch("/system-users");
    if (!res.ok) return { users: [], failed: true };
    const parsed = systemUsersResponseSchema.safeParse(await res.json());
    return parsed.success
      ? { users: parsed.data.system_users, failed: false }
      : { users: [], failed: true };
  } catch {
    return { users: [], failed: true };
  }
}

export async function getSystemUsers() {
  const result = await getSystemUserOptions();
  return result.users;
}
