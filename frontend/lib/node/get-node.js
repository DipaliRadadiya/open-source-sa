import { cache } from "react";
import { z } from "zod";
import { read } from "@/lib/api/read";
import { nodeGroupSchema } from "@/lib/schemas/node";

// Imported, never restated inline: a copy of the shape here would keep
// rejecting responses after the schema file was updated, and the page would
// read "we couldn't load Node" with nothing actually wrong.
const nodeResponseSchema = z.object({ node: nodeGroupSchema });

/** Everything the Node screen needs, in one call. */
export const getNode = cache(async function getNode() {
  const result = await read("/node", nodeResponseSchema);

  // Every field `read()` knows, not just whether it worked: without the
  // status and the kind, the failure box on this screen could not tell a
  // 403 from a 500 and printed the same unfalsifiable sentence for both.
  return {
    data: result.failed ? null : (result.data.node ?? null),
    failed: result.failed,
    status: result.status,
    failure: result.failure,
    message: result.message,
    debug: result.debug,
  };
});
