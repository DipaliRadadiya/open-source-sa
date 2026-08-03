import { cache } from "react";
import { serverFetch } from "@/lib/api/server-fetch";
import { exportsResponseSchema } from "@/lib/schemas/database";

/**
 * Every dump on the server, newest first.
 *
 * Global rather than per-database on purpose: rows survive their database being
 * deleted, so scoping the request to one id would hide exactly the dump someone
 * is most likely hunting for. Callers filter.
 */
export const getExports = cache(async function getExports() {
  try {
    const res = await serverFetch("/databases/exports");
    if (!res.ok) return { exports: [], failed: true };

    const parsed = exportsResponseSchema.safeParse(await res.json());
    return parsed.success
      ? { exports: parsed.data.exports, failed: false }
      : { exports: [], failed: true };
  } catch {
    return { exports: [], failed: true };
  }
});
