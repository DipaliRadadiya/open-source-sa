import { cache } from "react";
import { serverFetch } from "@/lib/api/server-fetch";
import { databaseResponseSchema } from "@/lib/schemas/database";

/**
 * One database and its users. The detail response embeds `users`, so the nested
 * list endpoint is only needed when refreshing them on their own.
 */
export const getDatabase = cache(async function getDatabase(id) {
  try {
    const res = await serverFetch(`/databases/${id}`);
    if (!res.ok) return { data: null, failed: true, status: res.status };

    const parsed = databaseResponseSchema.safeParse(await res.json());
    return parsed.success
      ? { data: parsed.data.database, failed: false }
      : { data: null, failed: true };
  } catch {
    return { data: null, failed: true };
  }
});
