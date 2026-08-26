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
    if (!parsed.success) {
      const issue = parsed.error.issues?.[0];
      console.error(
        `[get-database] database ${id} shape mismatch${issue ? `: ${issue.path?.join(".")} — ${issue.message}` : ""}`,
      );
      return { data: null, failed: true, status: res.status, failure: "shape" };
    }
    return { data: parsed.data.database, failed: false, status: res.status, failure: null };
  } catch (error) {
    console.error(`[get-database] database ${id} network error:`, error?.message);
    return { data: null, failed: true, status: null, failure: "network" };
  }
});
