import { cache } from "react";
import { parsedOr } from "@/lib/api/parse-response";
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

    // This is where `requested_by` was declared a string and arrives as an
    // object: the list silently stayed empty for hours with nothing in the
    // console to say why. The warning is the point.
    const parsed = parsedOr(exportsResponseSchema, await res.json(), "getExports");

    return parsed ? { exports: parsed.exports, failed: false } : { exports: [], failed: true };
  } catch {
    return { exports: [], failed: true };
  }
});
