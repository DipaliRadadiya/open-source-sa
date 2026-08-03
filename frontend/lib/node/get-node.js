import { cache } from "react";
import { z } from "zod";
import { serverFetch } from "@/lib/api/server-fetch";
import { nodeGroupSchema } from "@/lib/schemas/node";

// Imported, never restated inline: a copy of the shape here would keep
// rejecting responses after the schema file was updated, and the page would
// read "we couldn't load Node" with nothing actually wrong.
const nodeResponseSchema = z.object({ node: nodeGroupSchema });

/** Everything the Node screen needs, in one call. */
export const getNode = cache(async function getNode() {
  try {
    const res = await serverFetch("/node");
    if (!res.ok) return { data: null, failed: true };

    const parsed = nodeResponseSchema.safeParse(await res.json());
    return parsed.success ? { data: parsed.data.node, failed: false } : { data: null, failed: true };
  } catch {
    return { data: null, failed: true };
  }
});
