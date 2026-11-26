import { z } from "zod";

export const LOG_GROUPS = ["web", "database", "php", "system", "security", "daemon"];

export const LINE_OPTIONS = [100, 200, 500, 1000, 5000];

export const MIN_LINES = 1;
export const MAX_LINES = 5000;

export function normalizeLineCount(value) {
  const n = Number.parseInt(String(value ?? "").trim(), 10);
  if (!Number.isFinite(n)) return null;
  return Math.min(Math.max(n, MIN_LINES), MAX_LINES);
}

export const logSourceSchema = z.object({
  key: z.string(),
  label: z.string(),
  group: z.string(),
  size: z.number().nullable().optional(),
  modified: z.string().nullable().optional(),
  readable: z.boolean(),
  // Must be declared or Zod strips it.
  clearable: z.boolean().optional().default(false),
  clear_sensitive: z.boolean().optional().default(false),
  follow: z.boolean().optional().default(true),
  downloadable: z.boolean().optional().default(true),
});

export const logSourcesResponseSchema = z.object({
  logs: z.array(logSourceSchema),
});

export const logReadSchema = z.object({
  key: z.string(),
  label: z.string(),
  group: z.string(),
  lines: z.array(z.string()),
  cursor: z.number().nullable(),
  truncated: z.boolean().optional(),
  search_window_capped: z.boolean().optional().default(false),
});

export const logReadResponseSchema = z.object({ log: logReadSchema });
