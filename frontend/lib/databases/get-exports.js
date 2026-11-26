import { cache } from "react";
import { read } from "@/lib/api/read";
import { exportsResponseSchema } from "@/lib/schemas/database";

export const getExports = cache(async function getExports() {
  const result = await read("/databases/exports", exportsResponseSchema);

  return { exports: result.failed ? [] : (result.data?.exports ?? []), failed: result.failed, status: result.status, failure: result.failure, message: result.message, debug: result.debug };
});
