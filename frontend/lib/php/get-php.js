import { cache } from "react";
import { z } from "zod";
import { read } from "@/lib/api/read";
import { phpGroupSchema } from "@/lib/schemas/php";

// Shape imported from the schema file, never restated inline.
const phpResponseSchema = z.object({ php: phpGroupSchema });

/** Everything the PHP screen needs, in one call, behind the `php` permission. */
export const getPhp = cache(async function getPhp() {
  const result = await read("/php", phpResponseSchema);

  // Pass through every field `read()` knows so the failure box can tell a 403 from a 500.
  return {
    data: result.failed ? null : (result.data.php ?? null),
    failed: result.failed,
    status: result.status,
    failure: result.failure,
    message: result.message,
    debug: result.debug,
  };
});
