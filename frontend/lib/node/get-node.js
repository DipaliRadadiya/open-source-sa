import { cache } from "react";
import { z } from "zod";
import { read } from "@/lib/api/read";
import { nodeGroupSchema } from "@/lib/schemas/node";

const nodeResponseSchema = z.object({ node: nodeGroupSchema });

export const getNode = cache(async function getNode() {
  const result = await read("/node", nodeResponseSchema);

  return {
    data: result.failed ? null : (result.data.node ?? null),
    failed: result.failed,
    status: result.status,
    failure: result.failure,
    message: result.message,
    debug: result.debug,
  };
});
