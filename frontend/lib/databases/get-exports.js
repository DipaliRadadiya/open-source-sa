import { cache } from "react";
import { read } from "@/lib/api/read";
import { exportsResponseSchema } from "@/lib/schemas/database";

/**
 * Every dump on the server, newest first. Global on purpose: dumps outlive
 * their database. Callers filter.
 */
export const getExports = cache(async function getExports() {
  const result = await read("/databases/exports", exportsResponseSchema);

  // Pass status and kind so the error box can say which failure it was.
  return { exports: result.failed ? [] : (result.data?.exports ?? []), failed: result.failed, status: result.status, failure: result.failure, message: result.message, debug: result.debug };
});
