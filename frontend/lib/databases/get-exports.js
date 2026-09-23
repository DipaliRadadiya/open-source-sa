import { cache } from "react";
import { read } from "@/lib/api/read";
import { exportsResponseSchema } from "@/lib/schemas/database";

/**
 * Every dump on the server, newest first.
 *
 * Global rather than per-database on purpose: rows survive their database being
 * deleted, so scoping the request to one id would hide exactly the dump someone
 * is most likely hunting for. Callers filter.
 */
export const getExports = cache(async function getExports() {
  const result = await read("/databases/exports", exportsResponseSchema);

  // WHICH failure, not just that there was one: without the status and the
  // kind, the error box on this screen printed the same sentence whether the
  // API refused, crashed, or was not there at all.
  return { exports: result.failed ? [] : (result.data?.exports ?? []), failed: result.failed, status: result.status, failure: result.failure, message: result.message, debug: result.debug };
});
