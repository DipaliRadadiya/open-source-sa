import { serverFetch } from "@/lib/api/server-fetch";
import { envHistoryResponseSchema } from "@/lib/schemas/environment";

/**
 * Who changed this application's `.env`, newest first.
 *
 * Returns the failure rather than an empty list, because they are not the same
 * sentence: "nobody has changed this file" and "the panel could not read the
 * record of who changed this file" would look identical on screen, and only one
 * of them should reassure anyone.
 */
export async function getEnvironmentHistory(id) {
  try {
    const res = await serverFetch(`/applications/${id}/environment/history`);
    if (!res.ok) return { history: null, failed: true };

    const parsed = envHistoryResponseSchema.safeParse(await res.json());

    return parsed.success
      ? { history: parsed.data.history, failed: false }
      : { history: null, failed: true };
  } catch {
    return { history: null, failed: true };
  }
}
