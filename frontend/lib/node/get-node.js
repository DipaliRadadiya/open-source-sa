import { cache } from "react";
import { z } from "zod";
import { read } from "@/lib/api/read";
import { nodeGroupSchema } from "@/lib/schemas/node";

// Imported, never restated inline: a stale copy would reject valid responses.
const nodeResponseSchema = z.object({ node: nodeGroupSchema });

/** Everything the Node screen needs, in one call. */
export const getNode = cache(async function getNode() {
  const result = await read("/node", nodeResponseSchema);

  // Pass every read() field so the failure box can tell a 403 from a 500.
  return {
    data: result.failed ? null : (result.data.node ?? null),
    failed: result.failed,
    status: result.status,
    failure: result.failure,
    message: result.message,
    debug: result.debug,
  };
});
