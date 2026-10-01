import { z } from "zod";

// No byte cursor (the client re-reads the last N lines) and no group, size or readable flag.
// `kind` is file | journal.
export const applicationLogSourceSchema = z
  .object({
    key: z.string(),
    label: z.string(),
    kind: z.string().nullish(),
    // false is normal: a site nobody has visited has no access log yet.
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
    // Whether a FILTERED read hit the line cap: distinguishes "not in your log"
    // from "only the end was searched". Distinct from `truncated`.
    search_window_capped: z.boolean().default(false),
  })
  .passthrough();

export const applicationLogResponseSchema = z.object({
  log: applicationLogSchema,
});
