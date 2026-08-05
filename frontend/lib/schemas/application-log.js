import { z } from "zod";

/**
 * A site's own logs. Unlike the server logs these carry no byte cursor (so no
 * incremental tail — the client re-reads the last N lines) and no group, size
 * or readable flag. `kind` is file | journal; a `journal` "application" source
 * only appears for a site that runs a process.
 */
export const applicationLogSourceSchema = z
  .object({
    key: z.string(),
    label: z.string(),
    kind: z.string().nullish(),
    // false is normal — a site nobody has visited has no access log yet.
    exists: z.boolean().default(false),
  })
  .passthrough();

export const applicationLogsResponseSchema = z.object({
  logs: z.array(applicationLogSourceSchema).default([]),
});

export const applicationLogSchema = z
  .object({
    key: z.string(),
    label: z.string().nullish(),
    kind: z.string().nullish(),
    exists: z.boolean().default(false),
    lines: z.array(z.string()).default([]),
    truncated: z.boolean().default(false),
  })
  .passthrough();

export const applicationLogResponseSchema = z.object({
  log: applicationLogSchema,
});
