import { cache } from "react";
import { z } from "zod";
import { read } from "@/lib/api/read";
import { phpGroupSchema } from "@/lib/schemas/php";

const phpResponseSchema = z.object({ php: phpGroupSchema });

export const getPhp = cache(async function getPhp() {
  const result = await read("/php", phpResponseSchema);

  return {
    data: result.failed ? null : (result.data.php ?? null),
    failed: result.failed,
    status: result.status,
    failure: result.failure,
    message: result.message,
    debug: result.debug,
  };
});
